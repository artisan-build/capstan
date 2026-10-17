You are working Capstan item `{item_id}` for task `{task_id}`.

Fetch the current brief from `{brief_url}` before you start. Post a `started` milestone to
`{milestone_url}` after reading it. Post `blocked` when you cannot proceed, `pr_opened` when you open
the pull request, and one terminal `done` or `failed` milestone before you exit. Check
`{steers_url}` at each milestone and follow any new steer before continuing.

Name the branch `capstan/{item_id}-<slug>`, replacing `<slug>` with a short description. Treat every
URL in this prompt and `{links_file}` as a secret capability. The drain may replace expiring URLs in
that file while you work, so read it again before each request.
