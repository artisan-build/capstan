<?php

namespace App\Postmaster;

use App\Models\DeviceCode;
use App\Support\CliTokenNames;
use App\Support\ServerIdentity;
use RuntimeException;

class OnboardingSnippet
{
    public function __construct(private ServerIdentity $identity) {}

    public function generate(): string
    {
        $serverName = (string) config('app.name', 'Capstan');
        $serverId = $this->identity->id();
        $pollUrl = $this->installUrl('api.postmaster.poll');
        $tokenUrl = $this->installUrl('api.cli.device.token');
        ['device_code' => $deviceCode, 'model' => $device] = DeviceCode::issue(
            CliTokenNames::sanitizeLabel(mb_substr($serverName.' postmaster', 0, 64)),
        );
        $verificationUrl = $this->installUrl('cli.device.verify', ['user_code' => $device->user_code]);
        $pollScriptLines = $this->pollScript($serverId, $pollUrl);
        $cronLine = '* * * * * "$HOME/.config/capstan/'.$serverId.'/poll.sh" # capstan-postmaster:'.$serverId;

        return implode("\n", [
            '#!/bin/sh',
            'set -eu',
            '',
            'CAPSTAN_SERVER_NAME='.$this->quote($serverName),
            'CAPSTAN_SERVER_ID='.$this->quote($serverId),
            'CAPSTAN_POLL_URL='.$this->quote($pollUrl),
            'CAPSTAN_TOKEN_URL='.$this->quote($tokenUrl),
            'CAPSTAN_VERIFY_URL='.$this->quote($verificationUrl),
            'CAPSTAN_USER_CODE='.$this->quote($device->user_code),
            'CAPSTAN_DEVICE_CODE='.$this->quote($deviceCode),
            'CAPSTAN_HOME="$HOME/.config/capstan/$CAPSTAN_SERVER_ID"',
            'CAPSTAN_TOKEN_FILE="$CAPSTAN_HOME/token"',
            'CAPSTAN_POLL_SCRIPT="$CAPSTAN_HOME/poll.sh"',
            'CAPSTAN_CRON_TAG="# capstan-postmaster:$CAPSTAN_SERVER_ID"',
            'CAPSTAN_CRON_LINE='.$this->quote($cronLine),
            '',
            $this->requireCommand('curl'),
            $this->requireCommand('php'),
            $this->requireCommand('crontab'),
            'umask 077',
            'mkdir -p "$CAPSTAN_HOME"',
            'printf \'%s\n%s\n\' '.
                $this->quote(__('Authorize :name in your browser:', ['name' => $serverName])).
                ' "$CAPSTAN_VERIFY_URL"',
            'case "$(uname -s)" in',
            '    Darwin) open "$CAPSTAN_VERIFY_URL" >/dev/null 2>&1 || true ;;',
            '    Linux) command -v xdg-open >/dev/null 2>&1 && xdg-open "$CAPSTAN_VERIFY_URL" >/dev/null 2>&1 || true ;;',
            'esac',
            '',
            'CAPSTAN_TOKEN=\'\'',
            'CAPSTAN_ATTEMPT=0',
            'while [ -z "$CAPSTAN_TOKEN" ] && [ "$CAPSTAN_ATTEMPT" -lt 120 ]; do',
            '    CAPSTAN_ATTEMPT=$((CAPSTAN_ATTEMPT + 1))',
            '    CAPSTAN_TOKEN_RESPONSE="$(curl --silent --show-error --request POST --header \'Content-Type: application/json\' --data "$(printf \'{\"device_code\":\"%s\"}\' "$CAPSTAN_DEVICE_CODE")" "$CAPSTAN_TOKEN_URL" || true)"',
            '    CAPSTAN_TOKEN="$(printf \'%s\' "$CAPSTAN_TOKEN_RESPONSE" | php -r \'$body = json_decode(stream_get_contents(STDIN)); if (is_object($body) && isset($body->token) && is_string($body->token)) { echo $body->token; }\')"',
            '    [ -n "$CAPSTAN_TOKEN" ] || sleep '.DeviceCode::POLL_INTERVAL_SECONDS,
            'done',
            '[ -n "$CAPSTAN_TOKEN" ] || { printf \'%s\n\' '.$this->quote(__('Authorization expired. Generate a new onboarding snippet and try again.')).' >&2; exit 1; }',
            'printf \'%s\' "$CAPSTAN_TOKEN" > "$CAPSTAN_TOKEN_FILE"',
            'chmod 600 "$CAPSTAN_TOKEN_FILE"',
            'unset CAPSTAN_TOKEN CAPSTAN_TOKEN_RESPONSE CAPSTAN_DEVICE_CODE',
            '',
            "printf '%s\\n' ".implode(' ', array_map($this->quote(...), $pollScriptLines)).' > "$CAPSTAN_POLL_SCRIPT"',
            'chmod 700 "$CAPSTAN_POLL_SCRIPT"',
            '( crontab -l 2>/dev/null | grep -F -v "$CAPSTAN_CRON_TAG" || true; printf \'%s\n\' "$CAPSTAN_CRON_LINE" ) | crontab -',
            '"$CAPSTAN_POLL_SCRIPT"',
            'printf \'%s\n\' '.$this->quote(__('Postmaster polling is installed. The spoke is pending until its first probe passes.')),
            '',
        ]);
    }

    /** @return list<string> */
    private function pollScript(string $serverId, string $pollUrl): array
    {
        $probeParser = <<<'PHP'
$body = json_decode(stream_get_contents(STDIN));
if (! is_object($body) || ! isset($body->probe_challenge->probe_id, $body->probe_challenge->nonce)) {
    exit(0);
}
file_put_contents($argv[1], json_encode([
    'presence' => ['ready_inboxes' => []],
    'probe_response' => [
        'probe_id' => $body->probe_challenge->probe_id,
        'digest' => hash('sha256', $body->probe_challenge->nonce),
    ],
], JSON_THROW_ON_ERROR));
PHP;

        return [
            '#!/bin/sh',
            'set -eu',
            'CAPSTAN_SERVER_ID='.$this->quote($serverId),
            'CAPSTAN_POLL_URL='.$this->quote($pollUrl),
            'CAPSTAN_HOME="$HOME/.config/capstan/$CAPSTAN_SERVER_ID"',
            'CAPSTAN_TOKEN_FILE="$CAPSTAN_HOME/token"',
            'CAPSTAN_PENDING_FILE="$CAPSTAN_HOME/probe-response.json"',
            'CAPSTAN_PAYLOAD=\'{"presence":{"ready_inboxes":[]}}\'',
            '[ ! -s "$CAPSTAN_PENDING_FILE" ] || CAPSTAN_PAYLOAD="$(cat "$CAPSTAN_PENDING_FILE")"',
            'CAPSTAN_RESPONSE="$(printf \'header = "Authorization: Bearer %s"\n\' "$(cat "$CAPSTAN_TOKEN_FILE")" | curl --config - --fail --silent --show-error --max-time 45 --request POST --header \'Content-Type: application/json\' --data "$CAPSTAN_PAYLOAD" "$CAPSTAN_POLL_URL")"',
            'rm -f "$CAPSTAN_PENDING_FILE"',
            'printf \'%s\' "$CAPSTAN_RESPONSE" | php -r '.$this->quote($probeParser).' "$CAPSTAN_PENDING_FILE"',
        ];
    }

    /** @param array<string, scalar> $parameters */
    private function installUrl(string $route, array $parameters = []): string
    {
        $baseUrl = config('app.url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('APP_URL must be configured before generating a Postmaster onboarding snippet.');
        }

        return rtrim($baseUrl, '/').'/'.ltrim(route($route, $parameters, false), '/');
    }

    private function requireCommand(string $command): string
    {
        return 'command -v '.$command.' >/dev/null 2>&1 || { printf \'%s\n\' '.
            $this->quote(__(':command is required.', ['command' => $command])).' >&2; exit 1; }';
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
