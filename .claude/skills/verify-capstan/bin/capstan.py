#!/usr/bin/env python3
"""Drive a verify-capstan instance over HTTP. Run after bin/launch.sh, with the same CAPSTAN_VERIFY_* env.

  capstan.py token <purpose>            device flow -> token file in the state dir (0600); prints its PATH
  capstan.py get <path>                 GET as the logged-in owner; prints status + final URL + body
  capstan.py mcp <read|write> <tool> [json-args]
                                        tools/call on /mcp or /mcp/write; prints the JSON-RPC result
  capstan.py mcp-list <read|write>      tools/list; prints tool names (+ "destructive" if so annotated)
  capstan.py api <purpose> <METHOD> <path> [json]
                                        bound-credential API call (bearer + X-Capstan-Actor-ID = owner id)
  capstan.py fetch <url>                COOKIELESS GET; prints status, CSP/Set-Cookie headers, body
  capstan.py frames <path>              GET as owner; prints each iframe's UNESCAPED src and sandbox

Purposes: capstan.artifact.ingest, capstan.machine. The shared machine token drives Postmaster and Work.
Each device-flow token is BOUND to its API audience, so neither opens /mcp. The MCP doors take the
unbound `mcp` token that bin/mcp-token.sh mints. Tokens are never printed; read them from the file the
command names.
"""
import html
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

PORT = os.environ.get("CAPSTAN_VERIFY_PORT", "8412")
APP = f"http://{os.environ.get('CAPSTAN_VERIFY_APP_HOST', 'app2.localhost')}:{PORT}"
STATE = os.path.expanduser(f"~/Library/Caches/capstan-verify/state-{PORT}")
UA = "verify-capstan/1"


def owner():
    creds = {}
    with open(os.path.join(STATE, "owner.env")) as f:
        for line in f:
            k, _, v = line.strip().partition("=")
            creds[k] = v
    return creds


class Session:
    def __init__(self, jar_path=None):
        self.jar = http.cookiejar.MozillaCookieJar(jar_path) if jar_path else http.cookiejar.CookieJar()
        if jar_path and os.path.exists(jar_path):
            self.jar.load(ignore_discard=True)
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, method, path, data=None, headers=None, json_body=None):
        headers = {"User-Agent": UA, **(headers or {})}
        body = None
        if json_body is not None:
            body = json.dumps(json_body).encode()
            headers.setdefault("Content-Type", "application/json")
            headers.setdefault("Accept", "application/json")
        elif data is not None:
            body = urllib.parse.urlencode(data).encode()
            headers.setdefault("Content-Type", "application/x-www-form-urlencoded")
        req = urllib.request.Request(path if path.startswith("http") else APP + path, body, headers, method=method)
        try:
            with self.opener.open(req) as r:
                return r.status, r.geturl(), r.read().decode(errors="replace"), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.geturl(), e.read().decode(errors="replace"), dict(e.headers)
        finally:
            if isinstance(self.jar, http.cookiejar.MozillaCookieJar):
                self.jar.save(ignore_discard=True)

    @staticmethod
    def csrf(page):
        m = re.search(r'name="_token" value="([^"]+)"', page)
        if not m:
            sys.exit("FAIL: no CSRF token on page")
        return m.group(1)

    def login(self):
        # /bfc/login is rate limited (429 after a handful of attempts a minute), so the owner's
        # session is kept in the state dir and reused until it stops working.
        status, url, _, _ = self.request("GET", "/settings")
        if status == 200 and "/bfc/login" not in url:
            return self
        c = owner()
        _, _, page, _ = self.request("GET", "/bfc/login")
        status, url, _, _ = self.request("POST", "/bfc/login", data={
            "_token": self.csrf(page), "email": c["OWNER_EMAIL"], "password": c["OWNER_PASSWORD"]})
        if "/bfc/login" in url:
            sys.exit(f"FAIL: login landed on {url} ({status})")
        return self


def owner_session():
    return Session(os.path.join(STATE, "owner-cookies.txt")).login()


def token(purpose):
    s = owner_session()
    # The device request takes its CSRF from /settings. /bfc/ui does not exist (404).
    _, _, page, _ = s.request("GET", "/settings")
    status, _, body, _ = s.request("POST", "/bfc/device-authorizations", json_body={
        "app_purpose": purpose, "label": f"verify {purpose}"}, headers={"X-CSRF-TOKEN": s.csrf(page)})
    if status != 201:
        sys.exit(f"FAIL: device-authorizations {status}: {body[:300]}")
    device = json.loads(body)
    # Approve: GET /bfc/device with NO query string. ?user_code=... 404s "This authorization is unavailable."
    _, _, page, _ = s.request("GET", "/bfc/device")
    form = re.search(r'<form[^>]*/bfc/device"[^>]*>(.*?)</form>', page, re.S)
    fields = dict(re.findall(r'name="([^"]+)" value="([^"]*)"', form.group(1))) if form else {}
    if fields.get("action") != "approve" or fields.get("user_code") != device["user_code"]:
        sys.exit("FAIL: /bfc/device did not offer an approve form for this user_code")
    s.request("POST", "/bfc/device", data=fields)
    status, _, body, _ = Session().request("POST", "/bfc/device/token", json_body={"device_code": device["device_code"]})
    if status != 200 or "access_token" not in body:
        sys.exit(f"FAIL: /bfc/device/token {status}: {body[:300]}")
    os.makedirs(os.path.join(STATE, "tokens"), mode=0o700, exist_ok=True)
    path = os.path.join(STATE, "tokens", purpose)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as f:
        f.write(json.loads(body)["access_token"])
    print(path)


def mcp_post(door, payload):
    tok = open(os.path.join(STATE, "tokens", "mcp")).read().strip()
    path = "/mcp" if door == "read" else "/mcp/write"
    status, _, body, headers = Session().request("POST", path, json_body=payload, headers={
        "Authorization": f"Bearer {tok}", "Accept": "application/json, text/event-stream"})
    if status != 200:
        sys.exit(f"FAIL: {path} {status}: {body[:300]}")
    if "text/event-stream" in headers.get("Content-Type", ""):
        body = "".join(l[5:].strip() for l in body.splitlines() if l.startswith("data:"))
    return json.loads(body)


def main(argv):
    os.umask(0o077)
    if len(argv) < 2:
        sys.exit(__doc__)
    cmd = argv[1]
    if cmd == "token":
        token(argv[2])
    elif cmd == "get":
        status, url, body, _ = owner_session().request("GET", argv[2])
        print(status, url)
        print(body)
    elif cmd == "fetch":
        status, url, body, headers = Session().request("GET", argv[2])
        print(status, url)
        for h in ("Content-Type", "Content-Security-Policy", "Set-Cookie", "X-Robots-Tag"):
            print(f"{h}: {headers.get(h, '<absent>')}")
        print(body)
    elif cmd == "frames":
        _, _, body, _ = owner_session().request("GET", argv[2])
        for tag in re.findall(r"<iframe\b[^>]*>", body):
            src = re.search(r"""src=["']([^"']+)""", tag)
            sandbox = re.search(r"""sandbox=["']([^"']*)""", tag)
            # src is HTML-escaped; unescape or &amp; corrupts the signature (403/401 that is YOUR bug).
            print(json.dumps({"src": html.unescape(src.group(1)) if src else None,
                              "sandbox": sandbox.group(1) if sandbox else None}))
    elif cmd == "api":
        purpose, method, path = argv[2:5]
        tok = open(os.path.join(STATE, "tokens", purpose)).read().strip()
        status, _, body, _ = Session().request(method, path, json_body=json.loads(argv[5]) if len(argv) > 5 else None,
                                               headers={"Authorization": f"Bearer {tok}", "Accept": "application/json",
                                                        "X-Capstan-Actor-ID": owner()["OWNER_ID"]})
        print(status)
        print(body)
    elif cmd == "mcp-list":
        res = mcp_post(argv[2], {"jsonrpc": "2.0", "id": 1, "method": "tools/list"})
        for t in res.get("result", {}).get("tools", []):
            print(t["name"], "destructive" if (t.get("annotations") or {}).get("destructiveHint") else "")
    elif cmd == "mcp":
        args = json.loads(argv[4]) if len(argv) > 4 else {}
        print(json.dumps(mcp_post(argv[2], {"jsonrpc": "2.0", "id": 1, "method": "tools/call",
                                            "params": {"name": argv[3], "arguments": args}}), indent=2))
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main(sys.argv)
