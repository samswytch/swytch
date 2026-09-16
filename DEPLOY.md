# Deploying, with a web file manager and no shell

Written for someone working entirely through WePanel — no SSH, no command line.
Nothing here needs a terminal.

Everything you upload is in `dist/`, built by `bash bin/build-release.sh`:

| File | Where it goes |
|---|---|
| `plan.swytch.graphics.zip` | the document root |
| `coverapp.zip` | `/home/om44wfu4/coverapp/` |
| `password-hash.php` | only if you need it, then delete it |

Work through this in order. Steps 1 to 8 get the app running over plain HTTP;
9 and 10 put it behind HTTPS. **Do not do 10 before 9** — turning HTTPS on
before the certificate exists locks you out with no way back in.

---

## 1. Make the subdomain

In WePanel, create the subdomain **`plan`** on `swytch.graphics`, with its
document root at:

```
/home/om44wfu4/plan.swytch.graphics
```

Unlike `sam.` and `qr.`, this subdomain is new, so it also needs a DNS record.
Check it is pointing at the right place before you go further — open
`http://plan.swytch.graphics` in a browser. You want anything at all from the
server (a blank page, a directory listing, a 403). If you get "server not
found", DNS has not caught up yet; wait and try again before step 9.

## 2. Upload the application

Upload **`coverapp.zip`** to `/home/om44wfu4/` and extract it there.

You should end up with:

```
/home/om44wfu4/coverapp/src/
/home/om44wfu4/coverapp/bin/
/home/om44wfu4/coverapp/db/
/home/om44wfu4/coverapp/content/
```

If the file manager extracted it into a folder called `coverapp/coverapp/`,
move the inner one up a level. `coverapp/src/App.php` must exist at exactly
that path.

This directory is **above** the document root on purpose. It holds the brand
packs, which are internal documents and must not be reachable over the web.

## 3. Upload the site

Upload **`plan.swytch.graphics.zip`** into `/home/om44wfu4/plan.swytch.graphics`
and extract it there. You should end up with:

```
/home/om44wfu4/plan.swytch.graphics/index.php
/home/om44wfu4/plan.swytch.graphics/.htaccess
/home/om44wfu4/plan.swytch.graphics/robots.txt
/home/om44wfu4/plan.swytch.graphics/icon.svg
/home/om44wfu4/plan.swytch.graphics/assets/
```

**Check `.htaccess` is really there.** Most file managers hide files whose name
starts with a dot — look for a "show hidden files" setting and turn it on. If
`.htaccess` is missing, the front page will work and every other page will give
a 404. It is the single most common thing to go wrong here.

## 4. Make the data folder

Create an empty folder:

```
/home/om44wfu4/appdata
```

This is where the database, the backups and the API key live. It sits outside
the document root so none of it can be fetched over the web.

## 5. Get a password hash

The app stores a hash of the shared password, never the password itself.

Upload **`password-hash.php`** into `/home/om44wfu4/plan.swytch.graphics`, open
`http://plan.swytch.graphics/password-hash.php`, type the password you want to
use, and copy the line it gives you.

**Then delete `password-hash.php`.** It is not part of the app.

## 6. Write the configuration

Create a new file `/home/om44wfu4/appdata/config.php` with exactly this,
filling in the two blanks:

```php
<?php

return [
    'anthropic_api_key' => 'sk-ant-...',
    'anthropic_model'   => 'claude-opus-5',
    'assistant_effort'  => 'medium',

    'password_hash' => '...paste the line from step 5 here...',

    'daily_message_cap'   => 200,
    'session_message_cap' => 50,

    'data_dir' => '/home/om44wfu4/appdata',

    // Leave both false until the certificate exists. Step 10 turns them on.
    'cookie_secure' => false,
    'force_https'   => false,
];
```

The API key comes from console.anthropic.com. **Set a hard monthly spend cap on
that account before you go.**

## 7. Check the permissions

| Path | Permission | Why |
|---|---|---|
| `/home/om44wfu4/appdata` | `750` | PHP writes the database here. The folder itself has to be writable, not just the file — SQLite creates `-wal` and `-shm` files beside it. |
| `/home/om44wfu4/appdata/config.php` | `640` | Holds the API key. |
| `/home/om44wfu4/coverapp` and everything in it | `755` folders, `644` files | Read-only as far as the app is concerned. |
| `/home/om44wfu4/plan.swytch.graphics` and everything in it | `755` folders, `644` files | Same. |

If the file manager shows permissions as "owner/group/world" tick boxes rather
than numbers: folders want owner read+write+execute; files want owner
read+write. Nothing here needs world-write.

## 8. Open it

Go to `http://plan.swytch.graphics/health`. It will ask for the password first.

The page checks seven things and says which. **Every row must read "ok."**

You do not need to create the database — the app does that itself the first time
it is opened, and `/health` will say so. The most likely problems are:

| What it says | What to do |
|---|---|
| "The application files are not where this page expects them" | Step 2 — `coverapp/src/` is not beside the document root |
| "The configuration file is missing" | Step 6 — it must be at `/home/om44wfu4/appdata/config.php` |
| "could not be created" / "not writable" | Step 7 — the `appdata` folder needs to be writable |
| "could not be read" for the envelope or a pack | Step 2 — `coverapp/content/` did not extract |
| "mbstring is OFF" | PHP Parameters → Extensions → tick `mbstring`. The app runs without it, but a text-heavy assistant is better with it on. |
| 404 on `/health` but the front page works | Step 3 — `.htaccess` is missing |

Then ask the assistant one question. That is the only thing that proves the API
key works; `/health` deliberately does not spend money to find out.

## 9. Issue the certificate

In WePanel, SSL/TLS, issue a **Let's Encrypt** certificate for
`plan.swytch.graphics`.

Check the panel lists it as **auto-renewing**. Let's Encrypt certificates last
90 days — longer than the cover period, but not by much, and an expired one in
mid-October is a browser warning Sadie cannot click past with nobody available
to fix it.

Then open `https://plan.swytch.graphics` and confirm it loads with no warning.

## 10. Turn HTTPS on

Only once step 9 actually works. Edit `/home/om44wfu4/appdata/config.php` and
change the last two lines:

```php
    'cookie_secure' => true,
    'force_https'   => true,
```

Reload the site. It should now send you to `https://` on its own.

## 11. Add the nightly backup

In WePanel, Cron Jobs, add one job running daily at **02:17** with this command:

```
/usr/bin/php /home/om44wfu4/coverapp/bin/backup.php
```

In expert or raw-crontab mode, the whole line is:

```
17 2 * * * /usr/bin/php /home/om44wfu4/coverapp/bin/backup.php
```

Times in cron here are **UTC**. 02:17 UTC is 03:17 in British Summer Time and
02:17 after the clocks change on 25 October. For a backup that does not matter.

This writes a dated copy of the database into `appdata/backups/` and deletes
anything older than fourteen days. It is the only safety net if the database is
damaged while nobody can get in to fix it.

**If the job fails**, the usual cause is the PHP path. Try
`/usr/local/bin/php`, or whatever the panel's own cron examples use. You will
only find out that it failed if you do the next step.

## 12. Set the cron notification address

In the Cron Jobs screen there is a field for an email address. It is currently
empty, which means a failed backup reports to nobody. Put an address in it.

## 13. Before the trial week

- Load the real three weeks of cards, pasting the Asana links as you go. There
  is example content in there to show the shape — replace it.
- Download a CSV of the cards from the header. Keep it.
- Save the authority envelope somewhere Sadie can open it without the app.
- Give her the fallback line: if the app is down, work from Asana and the
  envelope document, note decisions for Sam, ask Kev for anything commercial or
  urgent, and carry on.

---

## Updating it later

Before 6 October only. Rebuild with `bash bin/build-release.sh`, then upload and
extract the two zips over the top. Nothing in `appdata/` is touched, so the
database, the backups and the configuration all survive.

After 6 October, nothing changes until Sam is back on the 26th.
