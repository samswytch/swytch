#!/usr/bin/env bash
#
# Mutation testing. Break one thing in the source, confirm tests/run.php
# notices, put it back. A mutation that SURVIVES is a gap in the tests, not a
# gap in the app.
#
#   COVER_CONFIG=/path/to/config.php bash tests/mutate.sh /path/to/config.php
#
# Every mutation restores its file whether it passed or failed, including when
# the patch could not be applied — so an interrupted run leaves nothing behind.
# If one ever does, `git status` will show it.
set -u
cd /home/user/swytch
CONFIG="$1"
PASS=0; SURVIVED=0
declare -a SURVIVORS

mutate () {
  local label="$1" file="$2" from="$3" to="$4"
  cp "$file" "$file.bak"
  python3 - "$file" "$from" "$to" <<'PY'
import sys
path, frm, to = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(path).read()
if frm not in s:
    sys.stderr.write("MUTATION DID NOT APPLY\n"); sys.exit(2)
open(path, 'w').write(s.replace(frm, to, 1))
PY
  if [ $? -ne 0 ]; then
    printf "  %-52s  !! could not apply\n" "$label"
    mv "$file.bak" "$file"; return
  fi
  out=$(COVER_CONFIG="$CONFIG" php tests/run.php -q 2>&1)
  mv "$file.bak" "$file"
  if echo "$out" | grep -q " 0 failed"; then
    printf "  %-52s  SURVIVED (no test caught it)\n" "$label"
    SURVIVED=$((SURVIVED+1)); SURVIVORS+=("$label")
  else
    local n; n=$(echo "$out" | grep -oE '[0-9]+ failed' | head -1)
    printf "  %-52s  caught (%s)\n" "$label" "$n"
    PASS=$((PASS+1))
  fi
}

echo "── the mutations the brief's rules depend on ──"
mutate "rule 2: drop the done exclusion"            src/Plan.php "WHERE status != 'done' AND due_date <= ?" "WHERE due_date <= ?"
mutate "rule 2: revert to the narrow reading"       src/Plan.php "WHERE status != 'done' AND due_date <= ?" "WHERE status = 'not_started' AND due_date <= ?"
mutate "rule 2: widen the window to four days"      src/Plan.php "private const SOON_DAYS = 2;" "private const SOON_DAYS = 4;"
mutate "rule 1: sort publish times backwards"       src/Plan.php "ORDER BY publish_time ASC, id ASC" "ORDER BY publish_time DESC, id ASC"
mutate "rule 1: include already-published cards"    src/Plan.php "AND publish_time IS NOT NULL AND status != 'done'" "AND publish_time IS NOT NULL"
mutate "rule 3: let it add more than one"           src/Plan.php "static fn(array \$c): string => 'oldest open item',
            1" "static fn(array \$c): string => 'oldest open item',
            9"
mutate "the cap: raise six to twelve"               src/Plan.php "public const CAP = 6;" "public const CAP = 12;"
mutate "the why-line: blank it"                     src/Plan.php "return 'due today' . \$started;" "return '';"
mutate "close-out: count yesterday as today"        src/Plan.php "\$from = \$midnight->setTimezone(\$utc)->format(Clock::STORED);" "\$from = \$midnight->modify('-1 day')->setTimezone(\$utc)->format(Clock::STORED);"

echo
echo "── the card form ──"
mutate "Monday: stop rejecting it"                  src/Cards.php "} elseif ((int) \$parsedDue->format('N') === 1) {" "} elseif (false) {"
mutate "Monday: suggest the wrong days"             src/Cards.php "\$friday = \$monday->modify('-3 days');" "\$friday = \$monday->modify('-4 days');"
mutate "required: let the title through empty"      src/Cards.php "if (\$title === '') {" "if (false) {"
mutate "required: let an unknown context through"   src/Cards.php "if (Contexts::find(\$contextKey) === null) {" "if (false) {"
mutate "publish_time: allow it on physical work"    src/Cards.php "} elseif (!in_array(\$stream, self::TIMED_STREAMS, true)) {" "} elseif (false) {"
mutate "dates: accept 31 February"                  src/Cards.php "return \$parsed->format('Y-m-d') === \$value ? \$parsed : null;" "return \$parsed;"
mutate "status: store whatever was posted"          src/Cards.php "if (!in_array(\$status, self::STATUSES, true)) {
            \$status = 'not_started';
        }" "if (false) {
            \$status = 'not_started';
        }"
mutate "completed_at: rewrite it on every save"     src/Cards.php "return \$existing['completed_at'] !== null ? (string) \$existing['completed_at'] : Clock::nowUtc();" "return Clock::nowUtc();"
mutate "completed_at: keep it after reopening"      src/Cards.php "if (\$status !== 'done') {
            return null;
        }" "if (false) {
            return null;
        }"

echo
echo "── the assistant and the log ──"
mutate "marker: stop stripping it"                  src/Outcomes.php "\$text = (string) preg_replace(self::PATTERN, '', \$raw);" "\$text = \$raw;"
mutate "marker: default a missing one to park"      src/Outcomes.php "\$outcome = self::GO_AHEAD;" "\$outcome = self::PARK;"
mutate "marker: stop logging ask_kev"               src/Outcomes.php "if (\$outcome === self::ASK_KEV) {
            return 'ask_kev';
        }" "if (false) {
            return 'ask_kev';
        }"
mutate "marker: start logging tier 1"               src/Outcomes.php "return null;
    }

    public static function label" "return 'decision';
    }

    public static function label"
mutate "log: allow a note to be overwritten"        src/LogBook.php "WHERE id = ? AND note IS NULL" "WHERE id = ?"
mutate "markdown: stop escaping text"               src/Markdown.php "\$out .= htmlspecialchars(\$rest, ENT_QUOTES, 'UTF-8');" "\$out .= \$rest;"
mutate "markdown: allow any link scheme"            src/Markdown.php "if (preg_match('#^(https?://|mailto:)#i', \$href) === 1) {" "if (true) {"
mutate "markdown: drop ENT_QUOTES from the href"    src/Markdown.php "\$out .= '<a href=\"' . htmlspecialchars(\$href, ENT_QUOTES, 'UTF-8')" "\$out .= '<a href=\"' . htmlspecialchars(\$href, ENT_NOQUOTES, 'UTF-8')"

echo
echo "── caps, attachments, auth, CSV, time ──"
mutate "caps: never reach the session cap"          src/Usage.php "if (\$session >= \$sessionCap) {" "if (false) {"
mutate "caps: count every day against today"        src/Usage.php "WHERE created_at >= ?',
            [Clock::startOfLondonDayUtc()]" "WHERE created_at >= ?',
            ['2000-01-01 00:00:00']"
mutate "attachments: skip the magic-byte check"     src/Conversation.php "if (!self::looksLike(\$mediaType, \$data)) {" "if (false) {"
mutate "attachments: allow any declared type"       src/Conversation.php "if (\$type === 'image' && !in_array(\$mediaType, self::IMAGE_TYPES, true)) {" "if (false) {"
mutate "attachments: drop the base64 check"         src/Conversation.php "if (\$data === '' || preg_match('#^[A-Za-z0-9+/]+={0,2}\$#', \$data) !== 1) {" "if (false) {"
mutate "attachments: drop the length limit"         src/Conversation.php "if (self::length(\$text) > self::MAX_TEXT_CHARS) {" "if (false) {"
mutate "conversation: allow it to end on assistant" src/Conversation.php "if (\$last === false || \$last['role'] !== 'user') {" "if (false) {"
mutate "auth: never throttle"                       src/Auth.php "return \$failures >= self::MAX_FAILURES;" "return false;"
mutate "auth: accept any password"                  src/Auth.php "if (!password_verify(\$password, \$this->config->passwordHash())) {" "if (false) {"
mutate "csv: stop defusing formulas"                src/Csv.php "if (\$text !== '' && strpbrk(\$text[0], '=+-@') !== false) {" "if (false) {"
mutate "csv: drop the byte order mark"              src/Csv.php "return \"\\u{FEFF}\" . implode" "return '' . implode"
mutate "clock: ignore the London timezone"          src/Clock.php "\$when = self::toLondon(\$storedUtc);

        return \$when === null ? '' : \$when->format('Y-m-d H:i');" "\$when = \$storedUtc === null ? null : new DateTimeImmutable(\$storedUtc, new DateTimeZone('UTC'));

        return \$when === null ? '' : \$when->format('Y-m-d H:i');"

echo
echo "── the card block and the config defaults ──"
mutate "card block: never add it"                   src/Prompt.php "if (\$card !== null) {" "if (false) {"
mutate "card block: cache it (a new prefix per card)" src/Prompt.php "\$blocks[] = ['type' => 'text', 'text' => self::describeCard(\$card)];" "\$blocks[] = ['type' => 'text', 'text' => self::describeCard(\$card), 'cache_control' => ['type' => 'ephemeral']];"
mutate "card block: leak the Asana link into it"    src/Prompt.php "'Status: ' . (Cards::STATUS_LABELS" "'Asana: ' . (string) \$card['asana_url'] . ' Status: ' . (Cards::STATUS_LABELS"
mutate "config: make a missing flag fatal again"    src/Config.php "return (\$this->values['cookie_secure'] ?? false) === true;" "return \$this->values['cookie_secure'] === true;"
mutate "config: default the model to nothing"       src/Config.php "return is_string(\$model) && trim(\$model) !== '' ? trim(\$model) : 'claude-opus-5';" "return is_string(\$model) ? trim(\$model) : '';"

echo
echo "════════════════════════════════════════════════════════"
echo "  caught: $PASS    survived: $SURVIVED"
if [ "$SURVIVED" -gt 0 ]; then
  echo
  echo "  Mutations no test noticed:"
  for s in "${SURVIVORS[@]}"; do echo "    - $s"; done
fi
