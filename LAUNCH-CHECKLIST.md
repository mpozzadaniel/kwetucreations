# Kwetu Creations — launch checklist

Everything below is a **hosting / business** step. The site files are ready as
far as they can be without a domain, and the intake system (§3) is already
built and tested on the machine this project was made on — it has simply never
run anywhere else. Nothing in this project claims the site is live, indexed, or
verified — those things only become true once the steps here have actually been
done.

---

## 1. Point the site at its real address

The pages deliberately ship **without** canonical URLs, `og:url`, `og:image`,
`robots.txt` or `sitemap.xml`, because the domain is not decided yet. One
command adds all of them:

```bash
php tools/set-domain.php https://www.YOUR-DOMAIN.com
```

It writes, for every page:

* `<link rel="canonical">`
* `og:url`, `og:image` (+ its real pixel width and height)
* `twitter:card` upgraded to `summary_large_image` **only** if the chosen
  image is at least 1200px wide and landscape
* the `@id`, `url` and `image` inside the `LocalBusiness` structured-data node
  on `index3.html`, `home.html`, `about.html` and `contact.html`, which go from
  the relative form they ship in to absolute addresses. The tool checks every
  node parses before it writes a single file.
* `robots.txt` with the `Sitemap:` line and `Disallow:` rules for `/admin/`,
  `/backend/`, `/tools/` and `/pre-fix-files/`
* `sitemap.xml` containing only the pages that are actually indexable

Options:

| switch | effect |
| --- | --- |
| `--og=images/webp/branding/spice.webp` | choose a different social preview |
| `--allow-insecure` | permit an `http://` staging host (never for production) |
| `--unset` | remove everything again, back to the current state |

Two decisions to make before running it:

1. **Which file is the homepage.** The site links to `index3.html`, so
   `set-domain.php` treats it as the homepage and gives `home.html` and
   `index.html` a `noindex` tag plus a canonical pointing at it. `index.html` is
   the old hero-only build, and a host that ignores `.htaccess` (no
   `AllowOverride`, or the dotfile never uploaded) serves it at `example.com/` —
   which is a visitor arriving at the site and finding one picture wall and no
   services, work, or testimonials. So `index.html` now carries
   `<meta http-equiv="refresh" content="0; url=index3.html">`: a relative target,
   correct at the domain root and inside a subfolder, that hands any such visitor
   to the complete homepage. The page still keeps its `noindex, follow`, so this
   is a routing safety net, not an invitation to index a duplicate. Prefer hosts
   that honour the `DirectoryIndex index3.html index.html` line in the root
   `.htaccess`; then the refresh never fires. If you decide to make
   `index.html` the real homepage instead, copy `index3.html` over it, set
   `HOME_AT_ROOT = true` at the top of `tools/set-domain.php` and run it again —
   the sitemap then lists `https://domain/` instead of
   `https://domain/index3.html`.
2. **The social preview.** Default is `images/logo/og-1200x630.webp` (1200×630)
   — the studio's own mark on white, built from the logo drop by
   `php -d extension=gd tools/make-logo-assets.php`. It replaced
   `images/webp/hall.webp`, which had stood in for it on 2026-10-26 when the
   stock "OPEN / BUSINESS HOURS" photo was removed. Pass
   `--og=images/webp/x.webp` instead if you would rather a project photo be the
   picture people see when the site is shared.

Then check both URLs in a browser: `https://domain/robots.txt` and
`https://domain/sitemap.xml`.

---

## 1a. The navigation and the footer live inside every page now

Until 2026-10-26 the two empty divs at the top and bottom of each page were
filled by `script.js`, which fetched `includes/header.html` and
`includes/footer.html` and pasted them in. A visitor saw the navigation, but the
HTML a crawler read first had no navigation, no footer, and one link in the
whole site pointing at the homepage.

`tools/bake-chrome.php` puts that markup into the pages themselves:

```bash
php tools/bake-chrome.php            # write the blocks into all 13 pages
php tools/bake-chrome.php --check    # report any page that has fallen behind
php tools/bake-chrome.php --unset    # empty the slots again, back to the fetch
```

It ran on 2026-10-26 and `--check` since reports `0 of 13 page(s) differ`.

* `includes/` is still the source of truth. **Edit the navigation there, then
  re-run the tool** — otherwise the pages keep the old copy and `--check` is the
  only thing that tells you.
* `script.js` leaves a slot alone when it already holds an element, so nothing
  loads twice and a page with the markup removed still gets it from the fetch.
* Cost measured the same day: `index3.html` went from 86,711 to 96,215 bytes raw
  (19,810 → 21,353 gzipped) and `services.html` from 47,681 to 58,774 raw
  (8,756 → 10,905 gzipped). Around 2 KB more per page compressed, in exchange
  for the menu, the footer and every service link existing in the HTML.
* The written markup is byte-for-byte the two files in `includes/` between two
  HTML comments, which is what makes `--check` able to tell stale from fresh.

---

## 2. Upload

Copy the whole folder — `index3.html`, the other pages, `css/`, `images/`,
`includes/`, `tools/`, `form-guard.js`, `script.js`, `cookie-consent.js`,
`cookie-policy.html`, `404.html`, `backend/` and `admin/`, plus `robots.txt`
and `sitemap.xml` once they are generated.

**The three `.htaccess` files are the access control**, and every FTP client and
zip unpacker hides dotfiles by default. After uploading, confirm these exist on
the server, in those exact folders: `backend/.htaccess`,
`backend/storage/.htaccess` and `admin/.htaccess`. If one is missing, the
database login file or the uploaded artwork is readable from the public web.
`backend/.htaccess` only works on Apache; §3.5 covers nginx.

Leave `backend/local-config.php` and `backend/admin-hash.php` **out** of the
upload and create them on the server instead (§3.2, §3.4) — a copy of your
password hash sitting in a zip file on a shared drive is still a copy.

Leave **`pre-fix-files/` out** as well. It holds a dated copy of almost every
page on the site, kept so any edit can be rolled back on this machine. Its own
`.htaccess` refuses it, and `robots.txt` now says `Disallow: /pre-fix-files/`
too, but a live site has no use for its own backups — upload the folder only if
a host forces you to.

Apache hosts (including XAMPP → cPanel moves) need this so the 404 page is
actually used and the fonts are not blocked:

```apache
ErrorDocument 404 /404.html
```

`404.html` references its own assets and links **relative to itself**
(`css/shared.css`, `images/favicon.svg`, `./` for the homepage, `script.js`), so the same
file renders correctly whether the site sits at a domain root or inside a
subfolder — it was rewritten this way on 2026-10-21 precisely so the page stops
depending on where it is mounted. `ErrorDocument` is the one part that cannot be
relative: Apache reads its path from the document root, so at a domain root the
line above is right as it stands. For a subfolder install it becomes:

```apache
ErrorDocument 404 /subfolder/404.html
```

The root `.htaccess` carries a second block that only fires for addresses
starting `/kwetu/`, which is how the site is mounted on this machine; Apache has
no way to know an `ErrorDocument` path should differ per mount point, and
`mod_if` is not loaded here. That block makes a wrong local address show the
branded page rather than Apache's grey one. It is inert at a domain root. Its
one cost is local: a missed **page** address inside `/kwetu/` is answered with
status 200 instead of 404. A missing stylesheet, image or script still gets a
real 404, and `*.php` under `backend/` is still refused with 403. Production
keeps the genuine 404 through `ErrorDocument`.

`style.css` in the project root is not referenced by any page. Confirm nothing
external needs it, then delete it — leaving it costs nothing but it is dead
weight.

---

## 2a. Making the site load faster

Measured on 2026-10-22 against the served homepage at `http://localhost/kwetu/`:
the page's own HTML, CSS and scripts total about **147 KB**, while the first
30 seconds pulled **83 pictures weighing 2.2 MB**. So the waiting is the photo
wall, not the code. The wall builds 14 tiles at a time on a wide screen
(`tilesPerCopy()` in `index3.html`) and shuffles them from a pool of 118
pictures, each of which has to be downloaded at some point.

Already done, with no effect on how the site looks:

- Pictures are WebP rather than JPEG, and no `@import` blocks the fonts.
- The root `.htaccess` now sets a cache lifetime. A page is `no-cache`, which
  means the browser always asks and gets a near-empty `304 Not Modified` when
  nothing changed, so an edit shows on the next reload. Pictures may be kept
  for 30 days, so a **second** visit no longer re-downloads the 2.2 MB.
  Styles and scripts are also `no-cache` — see the note below. Nothing is
  cached for the intake feed or the admin, which still send `no-store` from PHP.
- **Why `shared.css` is not given a day of freshness.** On 2026-10-22 a footer
  colour fix was written and verified on disk, and the site still showed the
  old blue: the browser had been told to hold `css/shared.css` for 24 hours, so
  across six page loads in a row it requested that file **zero times**. No
  reload, no ordinary refresh could see the fix. Revalidating costs one `304`
  of a few hundred bytes per page load, which is not worth an invisible change.
  After a caching rule is changed, a browser that already holds the old file
  needs **one** hard reload (Ctrl+F5) to drop it; every reload after that is
  honest.
- **Compression is on.** It was switched on 2026-10-22 without waiting for
  `mod_filter`, using a second block in the root `.htaccess` that runs
  `SetOutputFilter DEFLATE` — a core Apache command that needs only
  `mod_deflate`, which this machine already had loaded. Measured through Apache
  the same day, requesting with `Accept-Encoding: gzip`:

  | file | on disk | over the wire |
  | --- | --- | --- |
  | homepage | 86,711 b | 20,038 b |
  | `contact.html` | 21,389 b | 4,688 b |
  | `quote-request.html` | 19,507 b | 5,537 b |
  | `css/shared.css` | 32,646 b | 7,172 b |
  | `form-guard.js` | 17,067 b | 5,065 b |

  Pictures are deliberately left out (a `.webp` is already compressed, and
  squeezing it again only spends CPU) — `images/webp/abdec.webp` was confirmed
  to arrive at its full 37,802 bytes with no `Content-Encoding`. A client that
  does not ask for gzip still gets the plain file, so nothing can fail to read
  it. `Vary: Accept-Encoding` is set, so a cache keeps the two answers apart.
- The original `AddOutputFilterByType` block is still in the file and still
  guarded by **both** `<IfModule mod_filter.c>` and `<IfModule mod_deflate.c>`,
  and the new one is guarded by `<IfModule !mod_filter.c>`. That pairing is why
  you do not have to choose: load `mod_filter` later and the first block takes
  over while the second goes quiet on its own, so a response is never passed
  through DEFLATE twice.
- The failure this design is built around: enabling deflate alone earlier on
  2026-10-22 made the site answer **every** request with `HTTP 500` and
  `.htaccess: Invalid command 'AddOutputFilterByType'`, because Apache 2.4 reads
  that command from `mod_filter`, not from `mod_deflate`. `httpd -t` reported
  "Syntax OK" throughout — it never reads `.htaccess` files, so that check is no
  proof here. If the site shows HTTP 500 again right after a config change, the
  `.htaccess` is the first thing to look at, and the fix is to put the `#` back.

Still open, and not a code change:

1. **Load `mod_filter` anyway**, if you want the MIME-type block to be the one
   running. In `C:\xampp\apache\conf\httpd.conf`, remove the `#` from line 118
   and then Stop and Start Apache in the Control Panel:

   ```apache
   LoadModule filter_module  modules/mod_filter.so    # line 118
   ```

   This is now optional — the site already compresses without it. On a real host
   most providers load both modules already, so check the hosting control panel's
   Apache settings rather than editing a server file over your head.
2. **Shrink the picture pool**, if the first visit still feels slow. Cutting the
   wall's pool from 118 pictures to around 24 of the strongest would drop
   roughly 1.5 MB from that first 30 seconds. This one does change what a
   visitor sees — the wall repeats sooner and shows less of the archive — so it
   is a decision for the business, not an optimisation to slip in.

The cost of the 30-day picture cache, stated plainly: if a photo is **replaced
by a new file of the same name**, returning visitors keep seeing the old one for
up to a month. Give a replaced picture a new file name, or clear it with
Ctrl+F5 while checking.

---

## 2b. Connect Google Analytics (nothing tracks until you do)

The consent bar is live, but **no analytics and no advertising code exists on
this site**. `cookie-consent.js` will not load a tracker until both are true:

1. the visitor has accepted the Analytics category, and
2. a real Measurement ID is written into the file.

Until step 2 is done, accepting Analytics changes only the saved preference —
the network log stays empty of tracking requests. That is deliberate; do not
paste a guessed ID in.

To turn it on after the site is published:

1. In Analytics, **Admin → Data streams → Add stream → Web**, enter the live
   domain, and copy the **Measurement ID** (it looks like `G-XXXXXXXXXX`).
2. Open `cookie-consent.js` and set it at the top of the file:

   ```js
   const CONFIG = {
       version: 1,
       storageKey: "kwetu-consent-v1",
       analytics: { measurementId: "G-XXXXXXXXXX" }   // <- paste here
   };
   ```

3. Reload the live site, choose **Cookie Settings → Analytics → Accept All**,
   and confirm `googletagmanager.com/gtag/js?id=G-…` appears in the network
   log. Then reload, choose **Reject Non-Essential**, and confirm the request
   is **not** made. Real traffic should show up in Analytics → Realtime within
   about half an hour.
4. Update the wording in the same release, and do not skip it. On 2026-10-21 the
   client-facing pages stopped describing analytics as something pending and now
   state it as fact — "This website runs no analytics service" — in three places
   that all have to be rewritten the day the tag goes live:
   * `cookie-policy.html` §07 "Analytics and Advertising", the cookie inventory
     rows marked *Not installed*, and the two category headings in §03;
   * `privacy-policy.html` §08, the paragraph beginning "No analytics and no
     advertising run here";
   * `cookie-consent.js`, the Analytics `description` and `status` in
     `CATEGORIES`, which the preferences panel prints on screen.

   Change them to present tense and name the service (Google Analytics 4), with
   its cookie names and lifetimes, because a policy that understates what a site
   does is as inaccurate as one that overstates it. A visitor who accepted
   Analytics under the current wording gave permission for a service that did
   not exist; ask them again by raising `CONFIG.version` in the same release.
5. If a category is ever added or renamed, raise `CONFIG.version`. A stored
   consent record with an older version is discarded, so everyone is asked
   again under the new terms.

Marketing has no configuration point on purpose: no marketing or advertising
tracker is installed, and the preferences panel says so. Do not add an ID for a
tag that does not exist.

---

## 3. Put the intake backend on the host

The contact form, the quote request and the testimonial form all post to
`backend/`, and `admin/` is the private screen where somebody reads and approves
what arrives. This is the part that has to be *configured*, not just copied.

### 3.1 What the host must provide

| requirement | why |
| --- | --- |
| PHP **8.1 or newer** | the endpoints use `never`, `match` and `str_starts_with()` |
| MySQL 5.7+ / MariaDB 10.4+ | four tables, InnoDB, `utf8mb4` |
| Apache reading `.htaccess` (`AllowOverride All` on the site directory) | the `.htaccess` files **are** the access control |
| `upload_max_filesize` ≥ 8M | the quote form accepts four files of 8 MB each |
| `post_max_size` ≥ 40M | four 8 MB files arrive in one request, plus the form's own text |
| `max_file_uploads` ≥ 4 | PHP will silently drop the fifth file above its own ceiling (stock value is 20) |
| HTTPS | see 3.7 |

Ask the host for those three numbers and set the application's
`max_upload_bytes` / `max_upload_files` in `backend/local-config.php` to match
what the server actually allows — not the other way round. If the host insists
on 2 MB per file, lower the app to 2097152 and change the two sentences on
`quote-request.html` and `privacy-policy.html` that name the limit, or the site
promises something it will refuse. On this development machine the measured
values are `upload_max_filesize=40M`, `post_max_size=40M`,
`max_file_uploads=20`, so 8 MB and four files pass; that says nothing at all
about the hosting account.

If `AllowOverride` is `None`, every `.php` and `.sql` file under `backend/`
becomes publicly readable while the site still looks completely normal. Check
3.10 from a browser before you trust it.

### 3.2 Create the database

Make a database and a **dedicated user** for it — not the MySQL root account,
and not a user that owns anything else on the server. Grant only
`SELECT, INSERT, UPDATE, DELETE, CREATE` on that one database (the setup script
issues `CREATE TABLE IF NOT EXISTS`).

```bash
php backend/setup-database.php
```

It reads `backend/schema.sql`, applies it and prints the columns of every table
it finds. Four tables should appear: `testimonial_submissions`, `enquiries`,
`enquiry_files`, `admin_login_attempts`. If it prints a connection error, the
credentials in `backend/local-config.php` are not right yet.

### 3.3 Write the local configuration

```bash
cp backend/local-config.sample.php backend/local-config.php
```

Then edit `backend/local-config.php`:

* `db` — the host, name, user and password from 3.2.
* `ip_salt` — **change it.** It must not be the value used on this development
  machine, because it is what makes the stored IP fingerprints meaningless to
  anyone holding the database dump. Generate one:

  ```bash
  php backend/make-secret.php
  ```

* `enquiry_daily_limit_per_ip` (now **10**) and `daily_limit_per_ip` (now **8**)
  — how many enquiries, and how many testimonials, one visitor may send per
  rolling 24 hours. The counter uses a salted hash of the IP address, so an
  entire office behind one router, or a whole mobile-carrier city, shares a
  single allowance. These two numbers were raised from 4 and 5 for exactly that
  reason. They are anti-spam ceilings only: the CSRF token, the same-origin
  check, the honeypot, the minimum typing time and the file-type inspection all
  stay in force whatever these say, so raising them does not weaken anything.
  If spam ever arrives, lower these before adding any new mechanism.
* `admin_allow_from` — empty means any address may reach the sign-in form
  (password still required). Filling it is the stronger control: list the
  office IPs or CIDR ranges, e.g. `['196.43.10.0/24']`.
* `mail` — leave `enabled => false` until 3.8.

This file holds secrets. It is denied by `backend/.htaccess`, is not in the
upload list (see §2), and should be `chmod 640` or stricter. If the host lets
you put files above the document root, put it there instead and adjust the
`require_once` path — that is better than relying on `.htaccess`.

### 3.4 Uploaded artwork

`backend/storage/` is where quote attachments land. The web server process must
be able to write into it, and the folder must never be able to execute anything:

```bash
chgrp -R www-data backend/storage   # or apache, depending on the host
chmod -R 770 backend/storage
```

`backend/storage/.htaccess` already returns **403 for every file in it** — that
is deliberate, and it is what makes the folder safe to keep inside the site
tree. Files leave it only through `admin/attachment.php`, and only to a signed-in
reviewer on an allowed address. That script shows JPEG, PNG, WebP, GIF and PDF
inline — the bytes are re-read with `finfo` at that moment, not trusted from the
database row — and everything else, an SVG above all, stays a forced download,
because rendering a vector file would run its script on the admin's own session.
Do not loosen either of those.

### 3.5 On nginx instead of Apache

The four `.htaccess` files do nothing there. The equivalent is a location block
that allows only the four public endpoints and denies the rest of `/backend/`,
plus `internal;`-style protection for `backend/storage/`, and `deny all` for
`admin/` except through a passworded path. If the site is going to nginx,
`backend/local-config.php` and `backend/admin-hash.php` should live outside the
document root outright.

### 3.6 Set the review password

```bash
php admin/set-password.php            # generates a strong one, prints it once
php admin/set-password.php --choose   # type your own
```

Only the bcrypt hash is stored, in `backend/admin-hash.php`, and that file is
denied by `backend/.htaccess`. There is no recovery — re-running replaces the
password. **Do not launch with the password that is on this development
machine.** Anyone who ever saw this folder has seen it.

Once somebody knows the password, they change it themselves: sign in and use
**Change password** in the sidebar (`admin/password.php`). It asks for the
current password, and it never prints the new one back. Both routes enforce the
same rule, `admin_password_min_length` in `backend/config.php` (12 characters
here), and both refuse more than 72 because PHP's bcrypt stops reading at that
byte.

Either route also signs out every review session that is open, on every device:
each session remembers when `backend/admin-hash.php` last changed and stops
working the moment that moves. So if a password is suspected leaked, running the
terminal script is enough — no need to chase down who was signed in.

There is deliberately **no** forgotten-password page and no reset by email. A
public endpoint that sets this password without proving who is asking would be a
second door into the desk, and `admin/.htaccess` denies `set-password.php` over
the web as well as the script refusing anything but a terminal.

**Where that terminal actually is.** `admin/set-password.php` runs on the machine
that *hosts the site*, never on the laptop of whoever has forgotten the password.
How you reach it depends on the host you choose, so decide this before launch,
not in the moment you are locked out:

1. **Inside the desk — the everyday route, and the only one a reviewer needs.**
   Sign in with the current password, then **Change password** in the sidebar
   (`admin/password.php`). Works from any desktop, nothing installed, nothing
   uploaded.
2. **A host that gives you a shell** — a VPS over SSH, or cPanel's own *Terminal*
   icon. Run the command at the top of this section from the folder the site
   lives in. That is all there is to it.
3. **A shared host with no shell at all** — only FTP and File Manager, which is
   ordinary on the cheapest plans. The reset still works: make the hash on this
   development machine and upload the one file it lands in.

   ```bash
   # in E:\Explorer\MAYUNDO\kwetucreations — this machine has no php on PATH,
   # so use XAMPP's full path
   C:\xampp\php\php.exe admin/set-password.php --choose
   ```

   Upload **only** `backend/admin-hash.php` to the same path on the host, then
   set it to `600` in File Manager. A bcrypt hash carries nothing about the
   machine that produced it, so that file answers to the password you just typed
   wherever PHP runs. Keep the printed password: the file cannot be read back,
   only replaced.

**The trap in route 3.** Once that file is uploaded, the live site's password and
this machine's are no longer the same thing. If you later upload the whole
project folder from here, `backend/admin-hash.php` travels with it and quietly
swaps the live password for whatever this machine holds. Leave that one file out
of a bulk upload, or run route 3 again afterwards.

**And the limit over all three.** Whoever holds the hosting login or the FTP
password can do route 3, shell or no shell — the host sits above this desk, and
no code here can change that. Two things narrow the damage: a replaced hash ends
every open review session at once, so an unexplained sign-out is the sign
somebody else was in the terminal; and `admin_allow_from` (§3.3) keeps the
screens from answering any address outside the list, which is the control worth
populating as soon as the team's addresses are fixed.

### 3.7 HTTPS, and what it protects

Get the certificate (Let's Encrypt via cPanel or certbot) and force `http://` to
`https://` **before anyone signs in.** Until then the review password and every
typed enquiry cross the network in the open, which is why `admin/.htaccess` says
plainly not to moderate from a shared network without it. The forms themselves
work over plain HTTP, but a contact form over HTTP is a form whose contents can
be read in transit — so treat HTTPS as part of this step, not as a later polish.

### 3.8 Email notification to the team (optional, off by default)

`backend/notify.php` will send a plain-text copy of each new enquiry and each
new testimonial to the studio's own mailboxes, so somebody notices without
logging in. It stays switched off until `backend/local-config.php` has a real
account:

```php
'mail' => [
    'enabled'   => true,
    'host'      => 'smtp.your-provider.example',
    'port'      => 587,
    'security'  => 'starttls',      // or 'ssl' with port 465
    'username'  => 'alerts@kwetucreations.com',
    'password'  => 'app password, not the account password',
    'from'      => 'alerts@kwetucreations.com',
    'from_name' => 'Kwetu Creations website',
    'to'        => ['you@kwetucreations.com', 'studio@kwetucreations.com'],
],
```

Use an **app password** if the provider offers one. Notification is deliberately
non-authoritative: if the send fails, the enquiry is still saved, the visitor is
still told it was saved, and the failure goes to the PHP error log as
`kwetu notify failed: …`. Attachments are never emailed. Nothing here guesses a
provider — leaving `enabled => false` changes nothing about how the site behaves.

**Before any of the above is possible**, in this order: register the domain,
create the mailbox with a hosting or mail provider, then publish the records
that provider hands you — `A` (so the site resolves), `MX` (so `info@` receives),
and the `TXT` **SPF** line (so mail *you* send is believed). SPF is not optional
in practice: without it Gmail and Outlook will treat these notifications as spam
and the team will assume the site is silent. Nobody writes these values by hand
or looks them up in advance; they come from the provider's own setup screen.

`tools/check-mail.php` reads `backend/local-config.php` and reports which lines
are still empty, whether the SMTP host resolves, and whether its port answers —
without printing the password and without sending anything. It refuses to
connect while `host` is blank, so an unfilled sample file cannot reach
`smtp.example.com`.

```bash
C:\xampp\php\php.exe tools/check-mail.php          # read-only
C:\xampp\php\php.exe tools/check-mail.php --send   # posts one test note to mail.to
```

`--send` calls the very function a real submission calls, so a pass there means
a submission would have reached the inbox. As of 2026-10-22 it reports six
failures — host, username, password, from, to and `enabled` — because there is
no domain and therefore no mailbox yet. That is the accurate state, not a
broken sender.

### 3.9 WhatsApp: what is wired, and what is not

The footer carries a real WhatsApp link on every page: the green icon in
`includes/footer.html` and a "message us on WhatsApp" link in the sentence below
it both point at `https://wa.me/256787738999` with the message **"Hello Kwetu
Creations, I would like to ask about your services."** pre-filled (the wording
the business chose on 2026-10-22). The two form pages list WhatsApp as a
**channel row inside their existing contact box**, not as a button: `contact.html`
adds it as a fourth "Contact Information" row (Email, Phone, **WhatsApp**,
Location) and `quote-request.html` as a fifth row in its business card (Name,
Address, Contact number, Email, **WhatsApp**). Each shows the number itself and
keeps that box's icon tile, spacing and hover rules.

**Every icon on the site now wears a real colour** (the business's instruction,
2026-10-22, given after a first pass that only recoloured WhatsApp). The five
footer marks already did — WhatsApp `#25D366`, the Instagram gradient, TikTok
white, LinkedIn `#0A66C2`, Facebook `#1877F2` — and the channel glyphs join them:

| glyph | colour | where it appears |
| --- | --- | --- |
| map pin (`location-dot`, `map-marker-alt`) | `#EA4335` | footer, `contact.html` tile, quote card |
| handset (`phone`, `phone-alt`) | `#34A853` | footer, `contact.html` tile, quote card |
| envelope | `#1A73E8` | footer, `contact.html` tile |
| WhatsApp | `#25D366` | footer mark, `contact.html` tile, quote card |

Only the **glyph** changes: the text beside it and the gold tile behind it keep
the site palette, so the boxes still read as Kwetu. The rules win by
specificity (two classes beat the footer's `.footer-contact-item i`, which is one
class and an element), and nothing needed `!important`. `css/all.min.css` from
the CDN was checked and carries a single `color:` declaration in the whole file,
none on `.fa-*`, so FontAwesome cannot out-rank these.

Left alone deliberately: the `fa-arrow-right` inside the two submit buttons. It
is button furniture on Kwetu red, not a channel anyone has a colour for, and
painting it would change what a primary action looks like.
This is **click-to-chat** — the visitor's own WhatsApp app opens and *they* press
send. It is free, needs no API account, and the studio's phone number never
changes hands. The link does **not** carry what the visitor typed into the
form; it only opens the chat with that one sentence, and the submit button
remains the only action inside either form.

What it is **not**: a notification to you. Nothing on this site can post a
message into a WhatsApp account automatically. That is the WhatsApp Business
Cloud API, which needs a Meta-verified business, an approved number and a fee
per conversation — not worth taking on at launch. Until mail exists, an enquiry
still only appears in `/admin/`, so checking that screen is the current
notification system.

The number itself was confirmed by the business on 2026-10-22, in reply to
being asked whether it is a WhatsApp line — so the `wa.me` links stand. If that
ever changes, all **four** `wa.me` links go, across three files:
`includes/footer.html` (twice), `contact.html` and `quote-request.html`.
A grep for `wa.me` excluding `pre-fix-files/` should then return nothing.
The Instagram, TikTok, LinkedIn and Facebook icons remain deliberately
non-clickable, because no profile URL for them has been confirmed.

### 3.10 A teammate approving from their own laptop or phone

`admin/` is no longer restricted to this machine, so on the live domain
`https://domain/admin/` is the whole story: they need the URL, the password from
3.6, and — if `admin_allow_from` is populated — an address on that list. Hand
over the password through a channel you would not screenshot, and expect the
review screens to be used over HTTPS only.

### 3.11 Verify the intake, from the outside

Run these against the live domain. Every one of them was checked on this machine
before launch; the point of repeating them on the host is to prove *its* Apache
config, not the code.

| check | expected |
| --- | --- |
| `/backend/config.php`, `/backend/local-config.php`, `/backend/admin-hash.php`, `/backend/database.php`, `/backend/schema.sql` | **403** each |
| any file inside `/backend/storage/` | **403** |
| any file inside `/pre-fix-files/` (old copies of these pages) | **403** |
| `/tools/` | **403** |
| `/backend/csrf-token.php` | 200, JSON with a `token` |
| `/backend/submit-enquiry.php` with GET | **405** |
| `/backend/submit-testimonial.php` with GET | **405** |
| `/backend/approved-testimonials.php` | 200, JSON of approved testimonials only |
| `/admin/` in a browser with no session | redirect to `admin/login.php` |
| submit a contact enquiry | green "Saved" answer, and the row appears in `admin/inbox.php` |
| submit a quote request with a real image | answer counts the attachment; `attachment.php` downloads it |
| rename a `.php` file to `.png` and attach it | **refused**, nothing stored, and the form says so |
| write a testimonial, approve it in `admin/review.php` | it appears in the carousel on `testimonials.html` |
| reject it again | it disappears from the public JSON |

Then delete the test rows from `admin/inbox.php` and `admin/review.php` — the
delete button removes the record and its attachments together.

---

## 4. Google Search Console

1. Go to <https://search.google.com/search-console/about> and choose
   **Add property**.
2. Pick **Domain** if you can edit DNS (verifies every subdomain and protocol
   at once), otherwise **URL prefix** with the exact address, e.g.
   `https://www.YOUR-DOMAIN.com`.
3. For a Domain property, add the TXT record Google shows you to your DNS zone
   (in your registrar or cPanel), then click **Verify**. For a URL prefix,
   upload the `google*.html` verification file to the site root, or add the
   meta tag Google provides into the `<head>` of `index3.html`.
4. **Sitemaps** (left menu) → enter `sitemap.xml` → **Submit**.
5. Wait for *Discovered – currently not indexed* to start clearing. If pages
   stall, use **URL Inspection** on `https://domain/index3.html` → **Test
   Live URL** → **Request Indexing**. Do that for the homepage, `services.html`,
   `portfolio.html`, `about.html`, `contact.html` and `quote-request.html` —
   not for every page.
6. Check **Links → Top linked pages** after a week to confirm the internal
   linking is being followed.

Until steps 1–5 are done, **the site is not in Google's index** and no ranking
or "indexed" claim is true.

---

## 5. Google Business Profile

1. Go to <https://business.google.com>, sign in with the company Google
   account and **Manage now**.
2. Business name: `Kwetu Creations`. Category: start with **Sign maker**;
   add secondary categories that match the work actually delivered (e.g.
   *Graphic designer*, *Interior designer*, *Advertising agency* — only the ones
   the business genuinely offers).
3. Address: `Freedom House, Level 6, Suite R-724, Nkurumah Road, Kampala,
   Uganda`. Post the address exactly as it should appear on the map; the same
   wording must stay in the site footer.
4. Service area: only add it if the team really travels to clients — say which
   districts, and keep the site copy consistent with that claim.
5. Contact: website = the launched domain, phone `+256 787 738 999`, email
   `info@kwetucreations.com` (remove the duplicate `info@` entry if the profile
   already has one).
6. **Opening hours**: the website does not state them, so they must come from
   the business — do not guess a default.
7. Upload the real logo (square, ≥720px) and cover photos to the Google
   Business Profile. **The website side is done** — `images/logo/` now carries
   the header mark, the tab icon, the Apple touch icon and the share cover, all
   cut from `images/final.png` (1254×1254; the same chrome logo with its
   background already keyed out, swapped in on 2026-10-27 — see §8). For the
   profile itself, upload the original PNG, not the small web copies.
8. Verification is usually by postcard, phone or email — expect days, not
   minutes. The profile does not appear publicly until it is verified.
9. Once live, add the GBP reviews link to `testimonials.html` only if reviews
   are genuinely collected there. **Do not add `aggregateRating` to the
   structured data** — the site has no review counts, and inventing them is a
   rich-result penalty risk.

---

## 6. Details still needed from the business

These are marked in the files rather than invented. Send them over and the
pages can be updated:

* ~~**Logo file** (PNG/SVG)~~ — **delivered 2026-10-26, source changed twice on
  2026-10-27.** You dropped
  `images/kwetu-logo-3d.png` and
  `images/kwetu-logo-metal-black-holes-v2.png` (both 2048×2048, ~3.9 MB, solid
  white background,
  no alpha) and first picked the metal-black one; on 2026-10-27 you switched to
  the 3d one after it was measured to be twice as legible on the dark chrome
  (§8), then dropped `images/final.png` — the same artwork with its ground
  already transparent, which is now the tool's default source. It is in the header of all 13
  pages, the tab icon, the Apple touch icon, the About page and the default
  share cover; `images/favicon.svg` is no longer referenced by any public page
  but is still used by `admin/shell.php`, so leave the file alone. Two things
  still worth your eye:
  * **The header shows the mark and the text wordmark together** — the mark
    already spells KWETU CREATIONS, so if that reads doubled, say so and the
    text goes (it would then have to stay in the HTML as hidden text, because
    that is the site's only in-header brand name for search and screen readers).
  * **The tagline is now on the About page** ("...for a creative innovation.",
    read off the logo itself). If the wording is not exactly how the business
    writes it, correct it — it is quoted from the artwork, not from you.
  * ~~**The header mark is dark on a dark bar.**~~ **Resolved 2026-10-27** by
    using the 3d artwork instead (§8). `images/kwetu-------logo-3d.png` is a
    byte-identical duplicate of `images/kwetu-logo-3d.png` — the tool's default
    source is the clean name, so deleting the duplicate changes nothing.
* **Social media links** — the five footer marks (`whatsapp`, `instagram`,
  `tiktok`, `linkedin`, `facebook`) are in `includes/footer.html`, inside
  `<div class="social-icons-container">`, labelled for screen readers and styled
  by `css/shared.css`. Since 2026-10-22 **WhatsApp is a real `<a>`** to
  `https://wa.me/256787738999` with a pre-filled message, because the business
  confirmed that number the same day (§3.9).
  The other four are `<span>` elements rather than `<a>` because no official
  profile exists yet, and an icon that goes nowhere is worse than one that is
  simply not clickable yet. When a profile is live, turn that platform's
  mark into a link and keep the same classes and label:
  `<a class="icon-link instagram" href="https://the-real-profile-url"
  aria-label="Instagram" target="_blank" rel="noopener noreferrer">` with the
  existing `<i>` inside it. `.icon-link:focus-visible` already gives the
  keyboard a gold focus ring as soon as they are links. Do not point a mark at a
  search page, someone else's account, or `#`.
* **The domain and the mailbox** — `info@kwetucreations.com` is printed in the
  footer and in eight "write to us instead" messages in `backend/`, and today it
  is an address on a domain nobody owns, so any of those mails would bounce
  silently. Buying the domain and creating that mailbox is the single step that
  closes both this and §3.8; the checker at `tools/check-mail.php` lists exactly
  what is still missing.
* **Opening hours** — needed for Google Business Profile, and to finish the
  `LocalBusiness` JSON-LD block, which since 2026-10-26 is what the four
  structured-data pages publish. It still has no `openingHours` and no
  `geo` coordinates, because the business has never given either.
* **Company registration number** — still not supplied, so still not claimed
  anywhere. What *is* now stated, on the word of the business: the founder is
  **Stephen Were Mbatya**, who is also the **Marketing Director**, and the studio
  was founded in **2014**. (On 2026-10-26 the name was given as "Were Stephan";
  on 2026-10-27 the business supplied the full name, and every occurrence was
  changed — see §8. Do not write the short form again.) The second person named
  by the business is **Byuma Daniel, Creative Graphics Designer**. The name and
  date appear in the `about.html` prose ("founded by Stephen Were Mbatya in
  2014"), in the `foundingDate` / `founder` / `employee` keys of the structured
  data on all four pages that carry the `LocalBusiness` block, and in a new
  **Our People** section on About. The About meta description names the year,
  not the person. **The "city only, no street" answer was about the About
  prose, not the site**: the studio's street address already exists and stays
  where it was — `Freedom House, Level 6, Suite R-724, Nkurumah Road, Kampala,
  Uganda` is in `<address>` in `includes/footer.html` (so on all 13 pages,
  About included), in the contact page and in the `PostalAddress` of the
  structured data on those four pages. Only the About *story text* says
  "works from Kampala" and nothing more specific. If that split is not what was
  meant — if the street should come out of the footer too — say so before
  launch, because it is a Google Business Profile field as well (§5 step 3).
  If the registration number arrives, `about.html` and the `LocalBusiness`
  block are the only places it belongs.
* **Service area** beyond Kampala, if any, so the copy and GBP agree.
* **Client consent** for each portfolio image and each testimonial before it
  stays online — `testimonials.html` publishes the names and quotes that are
  already in the page; remove any the client has not approved.
* **The intake decisions, which are business decisions, not code.** The three
  forms now post to `backend/` and land in `admin/` (see §3), so what is still
  missing is: which mailboxes get the new-enquiry notifications and from which
  account they are sent (§3.8); who is allowed to hold the review password and
  whether `admin_allow_from` should list their addresses; and how long enquiries
  are kept before somebody archives or clears them — the Privacy Policy says
  plainly that nothing deletes automatically today, and it should be updated when
  a real schedule exists.
* **The payment and legal terms that only the business can set.** On 2026-10-21
  the in-page notes that admitted these were undecided were removed from
  `terms-and-conditions.html`, because a client should not read a to-do list
  inside a contract. What the page now says, and what still needs a real answer
  before launch:
  * §04 puts the deposit, milestones, balance and payment methods in the
    accepted quotation, and states that no studio-wide deposit exists. That is
    true only if every quotation really does carry those figures — if the studio
    would rather publish a standing rule (say a percentage and the methods it
    accepts), give the numbers and the section can say so directly.
  * §14 now stops at "subject to applicable law". A governing-law and
    jurisdiction clause (Uganda, and which courts) has deliberately not been
    written, because that is a legal choice, not a copy choice.
  * The whole page should be read once by somebody who can sign for the
    business before it goes live. It no longer says it is a draft; that does not
    make it approved.
* **Approved testimonials — three test rows are live and need your word.** This
  is no longer "nothing published": the feed at
  `backend/approved-testimonials.php` returns **6 rows** (every row with
  `status = 'approved' AND publication_consent = 1`), and all six render on the
  homepage carousel, on `testimonials.html`, and in the new featured slot on
  `about.html`. Read from the database on 2026-10-26:

  | id | name / business | rating | state |
  | --- | --- | --- | --- |
  | 1 | Rita Nakabuye / Serenity Spa Kampala | 5 | came from the intake form; keep if she consented |
  | 8 | IBRAHIM JUNIOR / Amazon | 4 | submitter writes in their own words; looks genuine |
  | 9 | IBRAHIM JUNIOR / mwave | 3 | same submitter |
  | 10 | mpozza Daniel / mavel | 4 | created 2026-10-24 during the admin-screen testing — you decide |
  | 11 | Copy Update Test / Local Test Entry | 5 | **definitely a test**: the text is "This row was created by the required success test for the updated feed" |
  | 12 | IBRAHIM JUNIOR / Amazon | 4 | reads "THE MISSON" — same business as row 8 |

  Rows 10-12 were approved by me on 2026-10-26 at 16:01:25-28 while verifying the
  feed, and every row in the table carries the same `ip_hash` because they were
  all posted from this machine, so the database cannot tell a real client from a
  test POST. **I will not change or delete a row without your word.** To pull
  one off the public pages, use `admin/review.php` and set it back to `pending`
  (that is what the screen is for; the feed's `WHERE` clause is the entire access
  control). Nothing else has to be edited: `testimonials-live.js` refetches and
  rebuilds the cards, the carousel and the About review box from whatever the
  feed returns. The About box used to be safe by accident — it showed one review
  chosen by stars-then-length, which is row 1 — but it now cycles all six, so
  row 11 is the second thing a reader can reach there too. No minimum-length or
  "looks like a test" filter was added to hide it, because that would silently
  hide a real short review as well; `admin/review.php` is the only honest lever.
* **A real client name for the placeholder tiles** — the 21 portfolio tiles and
  the eight service cards still describe categories, because inventing a
  business name for someone's project is the one thing this site must not do.

---

## 6b. Photographs that need a provenance check

The portfolio and service cards now describe each image truthfully, but a few
files do not look like photographs of Kwetu Creations work. Publishing them as
"our projects" would be an invented claim, so confirm each one or replace it:

| file | why it is in question |
| --- | --- |
| `images/webp/eng.webp` | a UK-style address plaque ("12 Riddell Close") and the picture still carries Instagram navigation arrows and a zoom button — it is a screenshot |
| `images/webp/bar.webp` | a round "Coffee Bar" plaque photographed on grass by a hand; no site context |
| `images/webp/signages/signage-2.webp` | a stock-looking "RECEPTION" wall shot |
| `images/webp/fabrication/studio.webp` | a stock-looking "STUDIO" slat wall |
| `images/webp/fabrication/coff.webp` | a close-up of "COFF…" letters, no identifiable premises |
| `images/webp/interiors/interior4.webp` | a 3D render, not a built room |
| `images/webp/interiors/good.webp` | a render / AI-style "Good Vibes Only" foliage wall |
| `images/webp/interiors/wall.webp` | an "INNOVATION" hex wall that reads as a stock interior |
| `images/webp/chri.webp` | carries a camera watermark crediting a company other than Kwetu Creations — crop it out or re-shoot |
| `images/webp/tv.webp` | a furnished living room, so it is now captioned as a feature-wall fit-out rather than "office interiors" |
| `images/webp/hero/abc.webp` | the ABC Capital Bank fascia shot carries a small watermark badge in its bottom-left corner; it is only used as `aria-hidden` wall decoration, so it never reads as a captioned project — but crop the badge out before reusing the photo anywhere else |

Two of these are structural, not cosmetic:

* `images/webp/interiors/wall.webp` and `images/webp/wall.webp` are the same
  photograph stored twice. Pick one path and delete the other.
* The hero wall is **not** automatic. It draws from a hardcoded `photos` array
  in each homepage variant (`index3.html` and `home.html` list 120 files, the
  `index.html` stub lists 118). A new photograph dropped into `images/webp/hero/`
  only appears once its path is added to that array — and it has to be added to
  all three variants together, or they drift. The stub is already behind by
  `images/webp/hero/mout.webp` and `images/webp/hero/teha.webp`; it only ever
  serves visitors who arrive at `/index.html` on a host that ignores the
  `DirectoryIndex` line, so it is low priority but not zero.
  To make the tile itself, use `php -d extension=gd tools/hero-tile.php
  images/x.jpeg` — see §8.
  As of this pass, every path in the arrays resolves (0 broken), but three files
  sit in `images/webp/hero/interiors/` unlisted: `display.webp`, `signage-2.webp`,
  `spice.webp`. Add them if they belong in the wall, or leave them out
  deliberately.

Removed 2026-10-26, on request ("remove that image that show the business hours
and also remove the image named gren.jpeg") — nine files, all held at
`C:\Users\EASTERN WIDE\Documents\Qoder\2026-10-19\284cb390\removed-images-2026-10-26\`
under their original relative paths, so any of them can be put back:

| file | notes |
| --- | --- |
| `images/interiors/open.jpg`, `images/signages/open.jpg` | byte-identical 3.7 MB copies of the stock "OPEN / BUSINESS HOURS" neon sign |
| `images/webp/interiors/open.webp`, `images/webp/signages/open.webp` | 105 KB derivatives of the same, identical to each other |
| `images/webp/hero/interiors/open.webp`, `images/webp/hero/signages/open.webp` | 17 KB wall-sized copies, identical to each other; these two were the only paths referenced from a page |
| `images/gren.jpeg` | the GREENERY project source |
| `images/webp/gren.webp`, `images/webp/hero/gren.webp` | its derivatives; `hero/gren.webp` was the only page reference |

Nothing else pointed at them: `tools/set-domain.php`'s default `og:image` was
repointed to `images/webp/hall.webp` first (see §1b), and `audit.js` still
reports 0 broken live files after the move.

---

## 7. After upload, re-check

* `https://domain/` loads the real homepage. `https://domain/index3.html` and
  `https://domain/index.html` both answer **301 to `/`** (the canonicaliser in
  the root `.htaccess`) and land on the same page, with no console errors and no
  404s in the network log. If the host does ignore `.htaccess`, `index.html`
  hands the visitor to `index3.html` through its meta refresh — check that it
  does, because that page is otherwise only a hero.
* On `/` itself, the header and footer **Home** link carries the active state.
  `setActiveNavigation()` in `script.js` treats `/`, `index.html` and `home.html`
  as the homepage, so the highlight survives whichever entry point the host
  actually serves.
* Every internal link resolves: header, footer, homepage sections, portfolio
  cards, service cards, the quote CTA on each page.
* `https://domain/includes/header.html` is reachable (the pages fetch it);
  if the host blocks it, inline the header into each page instead.
* Type a wrong address, e.g. `https://domain/no-such-page`, and confirm the
  branded page appears **and that the response is a real 404, not 200** — that
  is what `ErrorDocument 404 /404.html` is for. Its stylesheet, favicon and two
  scripts are referenced relative to the file, so check the network log has no
  failed request, and confirm the header and footer still load (they are fetched
  by `script.js`). Every button on it should stay on the site.
* Mobile: the hamburger opens and closes, no horizontal scrollbar on any page,
  portfolio filters and the image lightbox work with touch.
* Consent: the bar appears once on a first visit, **Cookie Settings** in the
  footer reopens the panel on every page, the choice survives a reload, and the
  network log shows no `googletagmanager.com` request unless the Analytics ID
  has actually been configured (see §2b).
* **The intake, top to bottom** — this is the part that breaks silently. A
  contact enquiry and a quote request with one file must appear in
  `admin/inbox.php` and the file must download; a testimonial must appear in
  `admin/review.php` and reach `testimonials.html` only after approval. Run the
  table in §3.11 and delete the test rows afterwards.
* The `.htaccess` files are on the server and doing their job (§2), and the
  host has not rewritten them into something else.
* The database user has no rights outside its own database, `backend/storage/`
  is not world-readable, and a fresh copy of `backend/local-config.php` has a
  **new** `ip_salt` (§3.3).
* Check the PHP error log once the site has taken real traffic. A submission
  that fails leaves a line there; the visitor only ever sees one plain sentence.
* Syntax-check every PHP file before uploading (a parse error in
  `backend/bootstrap.php` takes all three forms down at once):

  ```bash
  find backend admin tools -name '*.php' -print0 | xargs -0 -n1 php -l
  ```

* `tools/audit.js` checks titles, descriptions, alt text, broken paths, heading
  order and payload size for all pages. It needs node, which is not installed on
  the machine this project was built on — so it is run for you during a
  verification pass rather than treated as a step you have to perform.

---

## 8. What is true on the development machine right now

So the difference between "works here" and "works there" is not a mystery:

* XAMPP 8.2.12 at `C:\xampp`. Apache and MySQL were started by hand, so **both
  stop when the machine reboots** — restart them from the XAMPP control panel,
  or install them as services (`sudo httpd -k install` needs an elevated
  window on Windows).
* The site folder stays on `E:\Explorer\MAYUNDO\kwetucreations` and is reached
  through a junction: `C:\xampp\htdocs\kwetu` → that folder. URLs are
  `http://localhost/kwetu/index3.html` and
  `http://localhost/kwetu/admin/`.
* Database `kwetu_testimonials`, user `root`, **no password** — a XAMPP default
  that is only acceptable because nothing outside this machine can reach
  `127.0.0.1:3306`. On a host, use the dedicated user from §3.2 and a real
  password.
* `mail.enabled` is `false`, so no notification leaves the machine and the forms
  still report honestly. Nothing has been emailed from this project.
  `php tools/check-mail.php` was run on 2026-10-22 and reported six failures —
  no host, username, password, from address, recipient, and the switch itself —
  which is the true state of a site with no domain, not a broken sender. It has
  never connected to an SMTP server, because there is none to connect to.
* Text compression is **live on this machine** since 2026-10-22, through the
  `SetOutputFilter` block that needs only `mod_deflate` (§2a has the measured
  byte counts). It was verified by asking Apache for `gzip` and reading the
  answer back, not by assuming the file was correct.
* `pre-fix-files/` — every older copy of every page — is now **403 for the whole
  web**, by a `Require all denied` inside that folder. Before 2026-10-22 a
  backup page there was served like any other, which meant retired wording was
  readable and a search engine could have found duplicates of the live site. The
  files stay on disk to roll back from; only the web address is closed.
* Four live `wa.me` links exist (added 2026-10-22, §3.9): the footer icon and the
  footer sentence, which reach all thirteen pages, a WhatsApp row in the
  `contact.html` "Contact Information" box, and a WhatsApp row in the
  `quote-request.html` business card. They open the visitor's own WhatsApp with
  one chosen sentence pre-filled; they notify nobody.
  **None of them has been opened on a phone, and neither row has been seen
  rendered** — the browser tool is refused on this machine, so what is claimed
  here is the served markup, the served CSS, balanced tags and a clean
  `tools/audit.js` run. The icon tile, the wrapped number and how the row sits
  beside the address still need one look from a real device before anybody
  calls them finished.
* The review password on this machine is the generated one printed by
  `php admin/set-password.php` during development. Treat it as public and
  replace it before launch (§3.6). It has **not** been changed while building
  the Change Password screen on 2026-10-24: `backend/admin-hash.php` is
  byte-for-byte what it was (`md5 548a0bb875373859461bae1d57b722d9`), because
  every test of that screen ran against an isolated copy of `admin/` and
  `backend/` in the Qoder workspace, with its own throwaway password and its own
  database `kwetu_admin_test`.
* `admin_allow_from` is empty, so from this machine's point of view the review
  screens are reachable from any address the server can be reached from — which
  today is only this machine and anything on the same network. On a host, that
  becomes a real exposure until HTTPS and ideally an address list are in place.
* No canonical URLs, `robots.txt` or `sitemap.xml` exist, because no domain
  exists. The pages say "no domain yet" rather than pointing somewhere invented.
* `backend/storage/` holds whatever was attached during testing; clear it before
  launch along with the test rows (§3.11).
* What the intake held as at the evening of 2026-10-22 — the count has moved
  since, see the next bullet: **two testimonial rows, both `approved`, both
  real** — id 1 (Rita Nakabuye / Serenity Spa Kampala, 5★)
  and id 8 (IBRAHIM JUNIOR / Amazon, 4★) — so `approved-testimonials.php` returns
  `{"count":2}` and the carousel and `testimonials.html` show those two.
  On 2026-10-22 the three fabricated rows were deleted on instruction: ids 3
  "Audit Probe", 6 "Cap Verification" and 7 "Launch Review Check", all `pending`
  and all written by test POSTs from this machine, each of which had
  `publication_consent = 1` — the one careless click that would have published a
  review for a business that does not exist. They are still recoverable: a full
  `mysqldump` of the table taken immediately beforehand sits at
  `Documents/Qoder/2026-10-22/testimonial_submissions-backup-2026-10-22-before-fake-delete.sql`
  (outside the web root), and re-running it restores all five rows.
  Still present and still to clear before launch: **10 enquiry rows** (5 contact,
  5 quote), 9 attachment records and 9 files in `backend/storage/`, of which only
  two are real pictures — the rest are 1×1 test PNGs. Enquiry ids 9 and 10
  arrived between 18:24 and 18:28 on 2026-10-21 from a real browser page
  (`contact.html`, not a script) — the first end-to-end confirmation of the
  contact form through the UI rather than through `curl`.
* Re-counted on 2026-10-26 against the same local database: **11 enquiry rows**
  (5 contact, 6 quote), **10 attachment records**, **11 files** in
  `backend/storage/`, and **6 testimonial rows — all six `approved` with
  `publication_consent = 1`**, so `approved-testimonials.php` returns
  `{"count":6}` and nothing sits in `pending` any more. Three of those six are
  test rows that should not be on a public page (§6 has the id-by-id table and
  the unapprove route). Both areas were measured in the browser on 2026-10-26,
  not guessed: `#testimonials-track` on the homepage holds **6 slides and opens
  on id 12**, "★★★★☆ THE MISSON / IBRAHIM JUNIOR / Amazon", and the
  `testimonials.html` grid holds **6 cards in feed order**, with **"Copy Update
  Test / Local Test Entry"** as the second one. The feed sorts newest-reviewed
  first, and these three rows were approved most recently, so they are the first
  thing a visitor to either area reads.
* The sign-in page and the two review screens keep their CSRF token in
  `$_SESSION['admin_csrf']`, separate from the public forms' `$_SESSION['csrf']`, because
  `backend/csrf-token.php` re-issues the public one on every page load and used
  to wipe the admin token mid-session. If that split is ever undone, signing in
  breaks with "That form had expired" whenever a form page is open in another
  tab.
* That same message had a second cause, fixed 2026-10-26. The admin token
  expires after an hour, but only `$_SESSION['admin_csrf'] === empty` made
  `kwetu_admin_csrf()` issue a new one — so a tab left open past the hour kept
  being handed its own dead token on every reload, and no amount of reloading
  could sign in. Found from the live session file in `C:\xampp\tmp`: a token
  2 823 minutes old and no `login_attempts` key, which proves the password was
  never compared. `kwetu_admin_csrf()` now replaces an out-of-window token on
  the page load, and both the issue and the check read one constant,
  `KWETU_ADMIN_CSRF_SECONDS`. Verified in the staging copy: a fresh token
  reaches the password check, a 47-hour-old token is still refused as expired,
  and the next reload recovers the page — 8 assertions, 0 failures. The same
  test run against the pre-fix backup reproduced the stuck state exactly, so
  the bug was real and not a mistyped password. A sign-in page that has been
  open for over an hour now needs one reload instead of a cleared cookie.
* `admin/password.php` has been on the desk since 2026-10-24: the Change
  Password screen, reachable from the sidebar and from the dashboard. It requires
  the current password, matches the confirmation, applies the same length rule as
  the terminal script, and writes only a `password_hash()` result. Everything it
  does was run against the isolated staging copy first: wrong current password,
  mismatched confirmation, too short, longer than 72, identical to the one in
  use, forged token, missing token, a field sent as an array, five wrong answers
  in one session, the address throttle shared with the sign-in page, a successful
  change, sign-in with the superseded password (refused) and with the new one
  (accepted), and a session opened before the change bounced out with
  "You were signed out because the review password was changed" — including when
  the change came from the terminal rather than the screen.
* PHP on this machine reads `date.timezone = Europe/Berlin` out of
  `C:\xampp\php\php.ini`, while Windows and MySQL both run on Kampala time —
  measured 2026-10-24: MySQL `NOW()` said 16:15 while PHP `date('H:i:s')` said
  15:15, one hour apart, and `TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())` was
  −10800. Stored datetimes are unaffected, because `kwetu_admin_when()` parses
  and prints with the same setting, so they read back exactly as written. An
  *epoch* does not survive that gap: a file's modified time formatted with
  `date()` lands an hour before the owner's own wall clock. That is why the two
  places that report when the password was last replaced say "2 days ago" rather
  than a clock time, through `kwetu_admin_ago()`. Setting the host's PHP to
  `Africa/Kampala` would let those lines show a time of day again; that is a
  host decision, not something this project changes on its own.
* Two things are **verified only by HTTP, not by eye.** The embedded browser on
  this machine can load a page and read its DOM, but it has no visible surface
  unless the in-app Browser panel is open, so it cannot take a screenshot
  (`viewport=0x0`). The visual result of the 2026-10-21 copy pass — the five
  social marks restored to the footer column as non-clickable icons, the single
  full-width card in the homepage carousel, and the three-card grid on
  `testimonials.html` — and of the two service cards added on 2026-10-26 still
  has to be looked at in a real browser at `http://localhost/kwetu/` on a phone
  and on a desktop before launch. What the embedded browser **has** proven since
  then is structural: one navigation and one footer per page with the baked
  markup, the menu toggle opening and closing, the active-link highlight, the
  footer year, the cookie-settings button, `--nav-h` publishing 102px against
  `scroll-padding-top: 116px`, every service anchor present in order, and
  no console errors or warnings. The markup, the links, the CSS and the scripts
  behind them all pass their checks. The re-styled sign-in card (brand strip,
  gold-edged focus ring, show/hide eye) is in the same category: it is proven to
  render the right markup and classes over HTTP, not proven by eye.
* The SEO pass of 2026-10-26 changed the site in five ways, all reversible from
  `pre-fix-files/2026-10-26-seo-pass/`:
  1. the navigation and footer are baked into all 13 pages (§1a), so the homepage
     went from one crawlable inbound link to thirteen and every service anchor
     from none to thirteen;
  2. internal links point at `./` instead of `index3.html`, which the root
     `.htaccess` 301s — a link that arrives at its target without a redirect;
  3. `services.html` now carries eight cards with anchors (`#signage`,
     `#branding`, `#interiors`, `#illuminated-signage`, `#fabrication`,
     `#laser-cutting`, `#large-format-printing`, `#web-design`), the footer links
     to each one, and the four homepage tiles link to the four services they
     picture. Laser cutting and large-format printing were listed on the homepage
     and in the structured data but had no card of their own until now;
  4. the structured data node is `LocalBusiness` rather than `Organization`,
     gained an `OfferCatalog` of those eight services, and is now on
     `contact.html` as well as the two homepages and `about.html`. Nothing was
     added that the site does not already state: no opening hours, no service
     area, no coordinates, no social profiles and no review counts, because the
     business has never given any (§5, §6). Later the same day the node gained
     `foundingDate` and `founder` — those two are now stated, because the
     business confirmed them (§6).
  5. `set-domain.php` absolutizes that node and `robots.txt` refuses `/admin/`,
     `/backend/`, `/tools/` and `/pre-fix-files/`.
     Checked against a scratch copy, never against these pages: a run writes
     canonicals for 12 pages, a 10-URL sitemap and the robots file; a second run
     changes nothing; `--unset` returns every structured-data block
     byte-identical to the shipped form.

* **Website design and development is now offered as the eighth service**
  (2026-10-26, reversible from `pre-fix-files/2026-10-26-web-design-service/`).
  The quote form always listed "Website design" as a topic, so this makes the
  pages agree with the form rather than adding something new. What changed:
  an eighth card `#web-design` on `services.html`, a footer deep link (so all 13
  pages now link it, after re-running `php tools/bake-chrome.php`), the
  `LocalBusiness` node on the four structured-data pages (8 `knowsAbout` entries,
  8 offers), the homepage/about/contact service sentences, a new
  `subject` option on the contact form with the matching entry added to the
  backend topic allowlist in `backend/bootstrap.php` (without it the form would
  have refused the new choice), and the `services.html` title and description.
  **The card's picture is a hand-drawn SVG mock of a browser and a phone
  (`images/web-design.svg`), not a project.** Swap it for a screenshot of a real
  site the studio has built before launch, and rewrite the `alt` text to describe
  that screenshot. No client name, no platform, no hosting or maintenance claim
  was invented, and nothing on the card says how many sites have been delivered.

* **Two About-page changes, both from material that already existed**
  (2026-10-26, same backup folder). Neither invents anything:
  1. `about.html` turns the approved reviews over inside one box in "Why Work
     With Us" — a `<div class="about-review" data-featured-review>` mount
     (`<figure>` with the quote and caption, then the dots, then a
     screen-reader-only status line) after the difference-grid copy.
     `testimonials-live.js` owns it: `startFeatured()` fetches the same
     consent-checked feed (`data-testimonial-feed="backend/approved-testimonials.php"`),
     sorts with `featuredOrder()` — most stars first, fuller text breaking ties,
     the rule that used to pick the single static review — and advances every
     **6000 ms**, the same cadence as the homepage carousel. The caption shows
     the client's name and business only — the star count still sets the order
     but is not printed, removed 2026-10-26 on request ("remove the numbers that
     say outof on the testmonies on the about page"). It holds while the
     box is scrolled out of view, while the tab is hidden, while the pointer or
     the keyboard is inside it, and whenever `prefers-reduced-motion: reduce` is
     set; the dots stay clickable in that last case. Every client word goes
     through `textContent`. Before the feed answers, the box reads as an
     explanation of the consent rule rather than a quote, and if the feed is
     empty or unreachable it is left alone, so a dead server cannot put words in
     a client's mouth. Measured in the live DOM: 6 dots, the first review Rita
     Nakabuye / Serenity Spa Kampala, the current dot a 26px gold pill, `status`
     reading "Showing review 1 of 6"; clicking a dot moved the box and re-armed
     the timer; with `document.hidden` stubbed to `false` the box
     advanced 4 → 5 → 0 on schedule and wrapped back to the first. Caveats worth
     stating: the embedded browser reports the page as a hidden tab, so the
     un-stubbed auto-advance could not be observed there, `take_screenshot` is
     unavailable on this machine so nobody has seen the animation, and the
     reduced-motion branch was read in the code, not exercised.
     **Because the box now cycles the whole feed, the test rows are reachable on
     About too** — row 11 ("Copy Update Test") is the second review in the
     rotation order. Clearing rows 10-12 in `admin/review.php` (§6) is what fixes
     all three areas at once; no filtering rule was invented to hide them,
     because a short real review would have been hidden by it as well.
  2. "Client Reviews" is now a link in the footer EXPLORE column
     (`includes/footer.html`), then re-baked, so `php tools/bake-chrome.php
     --check` reads **0 of 13 page(s) differ from includes/** and all 13 pages
     carry `data-page="testimonials.html"` exactly once.
  Still open, cosmetic, undecided: the About difference grid keeps two columns
  between 651 and 950 px, where the review sits in a ~300 px column; and the
  expertise grid now ends with one orphan card in its last row.

* **The business-hours stock sign and the GREENERY photo are out of the site**
  (2026-10-26, reversible from `pre-fix-files/2026-10-26-remove-open-gren/` and
  from the holding folder listed in §6b). Asked as "remove that image that show
  the business hours / and also remove the image named gren.jpeg". What changed:
  `images/webp/hero/signages/open.webp` and `images/webp/hero/gren.webp` were
  deleted from the `photos` array in all three homepage variants (118 → 116 in
  `index3.html` and `home.html`, 116 → 114 in the `index.html` stub), then the
  nine `open.*` / `gren.*` files themselves moved out of the web root — see §6b
  for the full list. `tools/set-domain.php`'s default `og:image` was repointed to
  `images/webp/hall.webp` (Shemsande Hall facade lettering, 1280×720 landscape)
  before the file it pointed at disappeared; that is a brand-facing choice and
  needs your veto, and `--og=images/webp/x.webp` still overrides it. Measured
  after the move: `php -l tools/set-domain.php` clean, every quoted `images/...`
  path in the three homepages resolves to a real file, `tools/audit.js` still
  reports **16 broken / 89 warnings** with all 16 findings inside
  `pre-fix-files/` backups (0 in live files), `php tools/bake-chrome.php --check`
  reads **0 of 13 page(s) differ from includes/**, and `http://localhost/kwetu/index3.html`
  answers 200 for all 52 images the wall had loaded at that moment with no
  console errors. Not observed: the wall's animation and the remaining lazily
  loaded tiles (`take_screenshot` is unavailable on this machine). No portfolio
  tile or caption mentioned GREENERY, so no copy needed rewriting — grep for
  `greenergy` returns nothing outside the backup folders.

* **The four new photographs are on the homepage wall** (2026-10-26, reversible
  from `pre-fix-files/2026-10-26-hero-wall-new-photos/`). This had been written
  up as blocked on a missing WebP encoder; that was wrong. XAMPP ships
  `C:\xampp\php\ext\php_gd.dll` and `C:\xampp\php\php.ini` line 931 simply has
  it commented out (`;extension=gd`), so a WebP can be made on this machine
  without touching the shared config at all:

      /c/xampp/php/php.exe -d extension=gd tools/hero-tile.php images/abc.jpeg

  `tools/hero-tile.php` is the new helper: it resamples to the 640px width the
  wall uses, honours EXIF orientation, writes
  `images/webp/hero/<name>.webp` at `--q=78` and prints the before/after sizes.
  It is a CLI-only file in the already-denied `tools/` folder, and `php -l` is
  clean on it. What it made, each checked by eye after encoding:

  | tile | source | result | shows |
  | --- | --- | --- | --- |
  | `images/webp/hero/abc.webp` | `images/abc.jpeg` 1000×480, 74.6 KB | 640×307, 31.0 KB | ABC Capital Bank Ltd fascia |
  | `images/webp/hero/das.webp` | `images/das.jpeg` 1080×531, 11.6 KB | 640×315, 3.9 KB | Das Berliner Hotel illuminated sign at night |
  | `images/webp/hero/palm.webp` | `images/palm.jpeg` 1080×611, 27.9 KB | 640×362, 10.5 KB | Palm Gardens & Guest House lit sign, with the client's own phone numbers on it |
  | `images/webp/hero/loun.webp` | `images/loun.jpeg` 1040×780, 56.5 KB | 640×480, 20.8 KB | Movers Lounge lettering mid-install, technician on the scaffold |

  All four paths were inserted into the `photos` array of **all three** homepage
  variants in sorted position (120 tiles in `index3.html` and `home.html`, 118
  in the `index.html` stub), and `diff` against the backups shows exactly four
  added lines per file and nothing else. Measured after the edit: every path in
  all three arrays resolves to a real file, the served HTML at
  `http://localhost/kwetu/index3.html` contains the four new paths, each answers
  **200 `image/webp`**, `tools/audit.js` still reports **16 broken** with all 16
  inside `pre-fix-files/`, and the console is clean.
  Two things to read honestly: the wall is `aria-hidden` decoration, so these
  tiles carry no captions and no client claims; and **the new tiles were not
  watched rendering.** The mosaic holds back photographs more than `LOAD_AHEAD`
  (4) tiles from the viewport edge, and the embedded browser tab is hidden, which
  freezes the row animation, so the rows never travelled far enough to mount
  them. They are in the pool the dealing pointer walks end to end, which is the
  code's guarantee that nothing is skipped — but nobody has seen them on screen.
  Check them on your own phone or laptop before launch, and note that
  `images/abc.jpeg` carries a small watermark badge in its bottom-left corner
  (§6b).

* **The logo is on the site** (2026-10-26, reversible from
  `pre-fix-files/2026-10-26-logo-round/`). Source:
  `images/kwetu-logo-metal-black-holes-v2.png`, chosen over
  `images/kwetu-logo-3d.png`; both are 2048×2048 on a **solid white background
  with no alpha channel**, so the tool keys that white out to transparency
  before it resamples.
  `tools/make-logo-assets.php` (run it as
  `php -d extension=gd tools/make-logo-assets.php [source]`) finds the artwork's
  bounding box, crops a centred square and writes five files:

  | file | size | used for |
  | --- | --- | --- |
  | `images/logo/mark.webp` | 120×120, 7.9 KB, **alpha** | header badge on all 13 pages |
  | `images/logo/logo-480.webp` | 480×480, 46.9 KB, **alpha** | the "Our Mark" band on About |
  | `images/logo/favicon-32.png` | 32×32, 2.7 KB, on white | tab icon |
  | `images/logo/apple-touch-icon.png` | 180×180, 42.5 KB, on white | iOS home screen |
  | `images/logo/og-1200x630.webp` | 1200×630, 30.3 KB, on white | default `og:image` |

  The last three keep a white ground on purpose: a dark browser tab and an iOS
  home-screen tile would otherwise show the artwork's own dark metal with
  nothing behind it, and the share cover is a picture, not a cut-out.

  Wiring: one `<img class="logo-mark">` in `includes/header.html` then
  `php tools/bake-chrome.php`; the `.logo-mark` rule in `css/shared.css`
  (40×40, `object-fit: contain`, no ground since 2026-10-27 — see the next
  bullet); the icon line replaced in all 13 heads by a script that kept each
  file's own indentation and refused any page where it did not find exactly one
  match; the default
  `$ogImage` in `tools/set-domain.php`; and a new `about-mark` section between
  "Who We Are" and "Our Philosophy" whose copy states only the tagline and where
  the mark appears.
  Measured: `bake-chrome --check` **0 of 13 differ**; every new asset answers
  **200** with the right MIME type; the header mark lays out at 40×40 from a
  120×120 file; `about.html` in an iframe at 320, 360, 414 and 760 px shows
  `scrollWidth == clientWidth` on the navbar and **0 px horizontal overflow** on
  the document at every one of those widths; `audit.js` still reports
  **16 broken** with all 16 inside `pre-fix-files/`. The audit caught one thing
  on the first pass — the header `<img>` declared `width="40" height="40"`
  against a 120×120 file, which is exactly the intrinsic-size mismatch it checks
  for — and the declaration now matches the file, with CSS doing the scaling.
  Not observed: what any of it looks like. `take_screenshot` is unavailable on
  this machine, so the chip, the About band and the 32px tab icon (a detailed 3D
  mark does get muddy that small) need your eyes.

* **The white square behind the logo is gone** (2026-10-27, reversible from
  `pre-fix-files/2026-10-27-remove-white-square/` plus the pre-key asset copies
  in `pre-fix-files/2026-10-26-logo-round/images/logo/`). Asked to remove the
  white box the mark sat in, `tools/make-logo-assets.php` now runs
  `keyOutWhite()` before cropping: a pixel is background when its lowest colour
  channel is ≥238, and the 238–250 band fades linearly to full transparency
  while its RGB is un-matted against the removed white. The threshold came from
  a histogram of the source, not a guess — 35.11% of pixels are pure white and
  another 11.08% sit at 250–254, but only ~0.4% fall between 215 and 249, so
  almost nothing in the artwork is at risk.
  Two CSS grounds then came out: `background:#FFFFFF` + `border-radius:9px` from
  `#site-header .logo-mark` in `css/shared.css`, and `background:#FFFFFF` from
  `.about-page .about-mark-figure` in `about.html` (that card keeps its 1px gold
  hairline and 18px radius, so it is now an outline, not a white plate).
  Measured: served `mark.webp` is 40.3% fully transparent and `logo-480.webp`
  44.6%, corner pixel `rgba(0,0,0,0)` on both; computed
  `background-color: rgba(0,0,0,0)`, `border-radius: 0px` on the header mark and
  the About figure at `rgba(0,0,0,0)`; the About `<img>` decodes and lays out at
  338×338 inside its frame; the header stays at 40×40 from a 120×120 file with
  **0 px document overflow and 0 px navbar overflow at 320, 360, 414, 760 and
  1280 px**; `bake-chrome --check` **0 of 13 differ**; `audit.js` still
  **16 broken**, all inside `pre-fix-files/`, with no new live warning.
  **One thing this exposed, still yours to decide:** the artwork is dark brushed
  metal and the navbar is `#151515`, so with the white gone **59% of the mark's
  opaque pixels measure below 1.6:1 contrast against the bar** (mean 1.78:1 at
  120px, 1.92:1 at 480px). The red ring and the light KWETU CREATIONS bar carry
  it, but at 40px it is faint. If you want it to pop, the fix is not the white
  box back — it is a light or single-colour version of the mark for dark
  backgrounds, or dropping the header image and keeping the text wordmark.
  **Settled the same day by the swap below.**

* **The site now uses the chrome (3d) logo** (2026-10-27, later the same day as
  the key-out above; reversible from
  `pre-fix-files/2026-10-27-swap-to-3d/`, which holds the five metal-black
  derivatives and the older tool). You added `images/kwetu-------logo-3d.png`
  and asked which logo reads better; that file is **byte-identical** to
  `images/kwetu-logo-3d.png` (same md5 `a6b7508328f88a60fd4194375b01ce97`, same
  3,853,011 bytes), so no new artwork actually arrived — but it did force the
  comparison, and the 3d version won clearly. Measured on the same crop and
  key-out the site uses:

  | | on `#151515` at the real 40px | on white at the 32px tab-icon size |
  | --- | --- | --- |
  | metal-black (was) | mean **1.66:1**, 55.7% of ink below 1.6:1 | mean 12.30:1, 0% below |
  | 3d (now) | mean **3.54:1**, 26.2% of ink below 1.6:1 | mean 8.22:1, 5.2% below |

  Twice the legibility on the dark chrome, which is every surface the mark sits
  on, and the small loss on white is from 12:1 to 8:1 — still no problem.
  `tools/make-logo-assets.php`'s default source is now
  `images/kwetu-logo-3d.png`; run it the same way and it rewrote all five
  derivatives: `mark.webp` 9.3 KB, `logo-480.webp` 61.2 KB, `favicon-32.png`
  2.6 KB, `apple-touch-icon.png` 42.9 KB, `og-1200x630.webp` 30.2 KB (the sizes
  in the table earlier in this section are the metal-black build, superseded).
  Measured after the swap: served `mark.webp` is 9,528 bytes with 37.0%
  transparent pixels and ink mean luminance **91.0/255 against the old 52.3**,
  mean contrast **4.02:1** on the bar at 120px and **4.35:1** at 480px; the
  About image serves 62,664 bytes, corner `rgba(0,0,0,0)`, lays out at 338×338;
  both pages show **0 px document overflow**, computed `background-color:
  rgba(0,0,0,0)` on the header mark and the About figure, console clean;
  `bake-chrome --check` **0 of 13 differ**; `audit.js` unchanged at
  **16 broken / 118 warnings**, all 16 inside `pre-fix-files/`. No HTML or CSS
  changed in this round — the same five files were rewritten in place, which is
  the whole point of building them from one source.
  Still not observed by eye: `take_screenshot` does not work on this machine, so
  the composites in `Documents/Qoder/2026-10-19/284cb390/`
  (`mark-on-dark.png`, `logo-480-on-dark.png`, `compare-40.png`,
  `compare-480.png`, `compare-white.png`, `og-peek.png`, `favicon-peek.png`) are
  how this was judged. At 32px the tab icon loses the tagline plaque and the bar
  text entirely — the ring, the K and the red carry it. If you want a sharper
  tab icon, the fix is a tighter crop of just the circle for `favicon-32.png`,
  which is a small change to the tool.

* **The white square came back because of caching, not the code** (2026-10-27,
  reversible from `pre-fix-files/2026-10-27-cache-bust/`). Reported as "u cant
  remove that squre" after the key-out had already been verified in the files.
  The cause: `.htaccess` serves images as `public, max-age=2592000` — 30 days —
  and the derivatives keep their names, so a browser that had the site open on
  2026-10-26 still holds the **white-background** `mark.webp` and will not ask
  the server for it again. `curl -sI` confirmed it: `css/shared.css` is
  `Cache-Control: no-cache` (fixed after the same trap cost a colour change
  hours) while `images/logo/mark.webp` answers `max-age=2592000`. Measured on
  the live pages, nothing paints a box any more: walking the ancestor chain from
  the header mark and the About figure, every element is `rgba(0,0,0,0)` with
  `border: 0px none`, `outline-style: none` and no shadow, and the only painted
  backgrounds in the chain are the navbar and the body, both `rgb(21,21,21)`.
  Fixed by versioning the URLs so the old cached bytes can never be chosen
  again: `images/logo/mark.webp?v=2` in `includes/header.html` (re-baked),
  `logo-480.webp?v=2` on About, and `favicon-32.png?v=2` plus
  `apple-touch-icon.png?v=2` in all 13 heads, written by a script that refused
  any page without exactly one of each link. The About figure's gold hairline
  and 18px radius were also taken out in the same breath — **that part was
  reverted minutes later**, because the brief was "remove the white square" and
  the frame was never white and never asked about; `about.html` is now identical
  to its pre-round backup except for the four `?v=` tokens.
  `tools/audit.js` now strips `?query` and `#fragment` from `<img>` src before
  touching the disk, the way it already did for hrefs — without that, every
  versioned image reads as "missing image".
  Measured: `bake-chrome --check` **0 of 13 differ**; `audit.js` **16 broken**
  (unchanged, all in `pre-fix-files/`) with no missing-image or aspect-ratio
  complaint about a versioned URL; live `about.html` reports
  `mark.webp?v=2` in the header with `rgba(0,0,0,0)` behind it, the icon links
  carrying `?v=2`, the mark
  decoding at 480×480 inside a 340×340 box, **0 px document overflow**. (That run
  also logged the About figure at `border: 0px none` / `radius: 0px`; the revert
  above put the gold hairline and 18px radius back, so that reading is
  superseded — the figure is a framed card again, only without the white fill.)
  **What this means for you right now:** the fix only reaches a browser once it
  reloads a page whose HTML is not cached — which it is not (`no-cache`). So a
  plain reload should already show the keyed mark. If a phone still shows the
  white chip, it is holding the old HTML or the old image in a tab that has not
  reloaded: close the tab and open the site again.
  **The standing rule for future rebuilds:** regenerate, then bump `?v=` —
  `tools/make-logo-assets.php` says so in its own header comment now.

* **The logo source is now `images/final.png`, and it arrives pre-keyed**
  (2026-10-27, third logo round of the day; reversible from
  `pre-fix-files/2026-10-27-final-logo/`, which holds the five previous
  derivatives, the older tool, `includes/header.html` and all 14 page copies).
  You took the artwork to ChatGPT and asked for the square to be removed, then
  dropped the result in `images/`. Measured before adopting it: 1254×1254,
  1,498,199 bytes, **all four corners `alpha=127` (fully transparent)** and
  37.05% of the canvas fully clear — so the ground really is gone, not hidden.
  The wedges inside the circle are transparent too, which is the correct keyed
  look: the navbar shows through them. Two things that could have gone wrong and
  were checked rather than assumed:
  * **The white that remains is lettering, not background.** The K, the
    KWETU CREATIONS bar and the tagline are white ink (5.64% of the canvas is
    opaque white). Feeding this file to the old tool would have run
    `keyOutWhite()` over it and punched holes in exactly those parts. The tool
    now inspects the four corners first and skips the key-out when the ground is
    already clear, printing `source already has a transparent ground - skipping
    the white key-out`. Its bounding-box pass likewise stops treating near-white
    as ground in that mode, so the crop is driven by alpha.
  * **54% of the pixels sit at `alpha=1` rather than `0`** (99.2% opaque). That
    is a quirk of how the file was exported, not a haze: composited over
    `#151515` it is indistinguishable from opaque, and the rendered mark shows
    no plate or ghost edge.
  Rebuilt all five derivatives from it — `mark.webp` 7.5 KB, `logo-480.webp`
  42.3 KB, `favicon-32.png` 2.6 KB, `apple-touch-icon.png` 43.6 KB,
  `og-1200x630.webp` 31.7 KB — and verified the rebuild rather than trusting it:
  served `mark.webp` is 7,676 bytes, **36.7% ground clear and 7.85% opaque white
  lettering** (the lettering survived), `logo-480.webp` 43,276 bytes at 37.7% /
  9.98%. Cache tokens went `?v=2` → `?v=3` on all four logo URLs across the 13
  heads, `includes/header.html` and the About image, because the 30-day image
  rule from the round above applies to every rebuild.
  Gates: `bake-chrome --check` **0 of 13 differ**; `audit.js` **20 broken**, and
  all 20 are inside `pre-fix-files/` (the 4 new ones are this round's page
  copies), live pages **0 broken** with no missing-image or aspect-ratio
  finding; `curl` confirms `mark.webp?v=3` 200/7,676 B, `logo-480.webp?v=3`
  200/43,276 B, `favicon-32.png?v=3` 200/2,672 B, `apple-touch-icon.png?v=3`
  200/44,596 B, and the served homepage HTML carries
  `images/logo/mark.webp?v=3`. Renders judged from
  `Documents/Qoder/2026-10-19/284cb390/` (`final-on-dark.png`,
  `final-on-white.png`, `final-nav-40.png`, `new-mark.webp.png`,
  `new-logo-480.webp.png`) — `take_screenshot` still does not work here, so
  nobody has seen it in a real browser tab.
  One housekeeping note: `images/final.png` is 1.5 MB and sits in the web root
  like the earlier drops, so it is downloadable by URL. Nothing references it.
  Delete it, or move the drops out of `images/`, whenever you next tidy up —
  say the word and I will do it rather than remove your files unasked.

* **Three logo follow-ups: the gold frame, the hero word, and the tab icon**
  (2026-10-27, fourth round; reversible from
  `pre-fix-files/2026-10-27-gold-frame-hero-tab/` — five assets, the tool,
  `about.html`, `home.html`, `index3.html`).
  * **The gold frame around the About logo is gone.** This is the frame that was
    taken out and then restored earlier the same day because it had not been
    asked for; this time it was asked for in words ("why dont you remove that
    golden squre that is round it"), so `about.html` now has
    `.about-mark-figure { overflow: hidden }` with no border and no radius.
    Measured live: `border: 0px none`, `border-radius: 0px`,
    `background: rgba(0,0,0,0)`, `box-shadow: none`, box still 340×340.
  * **The homepage hero was tried with the logo, then put back to the word.**
    Asked to match the reference site, `<h1 class="slide-logo">` in `home.html`
    and `index3.html` briefly held `images/logo/logo-480.webp?v=3` instead of
    the words, with the brand name kept as an off-screen
    `.slide-wordmark` span. It measured fine (150→300 px across five widths,
    centred to 0 px, no overflow), but on review the mark is already in the
    navbar of every page and a detailed dark emblem competes with the moving
    photo wall behind it, so **the word is back** and the logo hero was undone
    the same day. Both files now differ from their pre-round backups by exactly
    one line each — the favicon `?v=4` — verified with `diff`. Measured after
    the restore: text `KWETU CREATIONS`, **no `<img>` in the h1**, type
    34.2/39.3/46.1/61.4/76 px at 360/414/768/1024/1440, `#F5F3EF` and
    `#B5122A` on the two spans, nothing clipped, overflow −15 px, console clean
    (six loads, zero errors). Reversible either way: the logo-hero version is in
    `pre-fix-files/2026-10-27-hero-word-back/`.
  * **"The nav bar of chrome still has the other logo with the white squre" was
    the browser tab, and it was true.** `favicon-32.png` measured **100% opaque
    with all four corners `#FFFFFF`** — it had been built on a white ground on
    purpose, which is exactly what a tab strip displays as a white chip. The
    tool now builds it with `ground = null`; the rebuilt file is 32×32 with
    **33.0% transparent** and three corners at `alpha=127`. Only that one file
    changed in the rebuild (`mark.webp`, `logo-480.webp`,
    `apple-touch-icon.png` and the og cover hash-identical to the previous
    build), so **only the favicon URL was bumped, to `?v=4`**, in all 13 heads;
    the others keep `?v=3` and their warm cache. `apple-touch-icon.png` stays on
    white deliberately — iOS does not blend a transparent home-screen tile.
    Verified served: `favicon-32.png?v=4` → 200, 2,862 B, `image/png`, and
    `about.html` reports the link carrying `?v=4`.
  Gates: `bake-chrome --check` **0 of 13 differ**; `audit.js` **20 broken**, all
  inside `pre-fix-files/`, live pages 0 broken.
  **If your tab still looks white, that is Chrome's own favicon cache, not the
  site** — it holds tab icons for a long time and ignores `?v=`. Check in a
  private window, or clear icons for the site, before assuming this failed.

* **The logo now appears in the footer and on the admin sign-in**
  (2026-10-27, fifth round; reversible from
  `pre-fix-files/2026-10-27-footer-admin-logo/` — `includes/footer.html`,
  `css/shared.css`, `admin/login.php`, `admin/shell.php`).
  * **Footer**: `includes/footer.html` `.footer-logo` gained
    `<img class="footer-logo-mark" src="images/logo/mark.webp?v=3" width="120"
    height="120" alt="">` ahead of the word spans — the same 40px badge the
    navbar uses, and the reason the `alt` is empty is that the name is written
    as text right next to it, so a screen reader must not say it twice.
    `css/shared.css` `.footer-logo` became `inline-flex` with `align-items:
    center` so the badge and the word sit on one centre line. Re-baked into all
    13 pages. Measured at 360/414/768/1024/1440: image decodes, box 40×40,
    lockup 270×40, vertical centre of badge within 3px of the text in every
    width, no overflow, console clean.
  * **Admin sign-in**: the brand panel in `admin/login.php` was text only. It
    now leads with `../images/logo/mark.webp?v=3` (relative path, because
    `admin/` is a subfolder), styled by a new `.login-logo` rule in
    `admin/shell.php` and `.login-mark` switched to flex with a 10px gap.
    Measured on the live page: decodes, 40×40, panel background `rgb(32,32,32)`
    so the keyed mark needs no plate, wordmark reads "KWETU. CREATIONS", zero
    document overflow. Both PHP files pass `php -l`.
  * **Deliberately not touched**: the admin **sidebar** on the signed-in screens
    still has the text-only `.side-brand`, and `admin/shell.php` still points
    its own tab icon at the old placeholder `images/favicon.svg`. Both are a
    one-line change each if you want them; the sidebar especially, since that is
    what you look at while working. **Both were done on the same day when you
    asked for them** - see the next bullet.
  Gates after the change: `bake-chrome --check` **0 of 13 differ**, `audit.js`
  **24 broken** — all of them inside `pre-fix-files/`, including the 4 this
  round's backup folder added — **0 live broken**, and the 18 live warnings
  unchanged, none of them about the new images.

* **The signed-in sidebar and the admin tab icon took the logo too**
  (2026-10-27, sixth round; reversible from
  `pre-fix-files/2026-10-27-admin-sidebar-icon/` — `admin/shell.php` and this
  file, md5-matched before editing). One file, because every admin screen —
  including the sign-in page — is drawn through `kwetu_admin_page()` in
  `admin/shell.php`, so its `<head>` is the only place an icon link exists.
  * **Sidebar**: `.side-brand` gained
    `<img class="side-logo" src="../images/logo/mark.webp?v=3" width="120"
    height="120" alt="" decoding="async">` as its first child. It is **stacked
    above** the wordmark, not beside it, and that is a measured decision, not a
    style preference: the sidebar is `grid-template-columns:268px` with
    `padding:0 24px` on the brand block, i.e. 220px of content box, and the
    17px/`.13em` "KWETU. CREATIONS" wordmark is already 219px wide on its own —
    a side-by-side 40px badge would have pushed it out of the panel. New rule
    `.side-logo { width:40px; height:40px; margin:0 0 9px; object-fit:contain }`
    matches the navbar and footer badge exactly. In the `@media (max-width:
    1040px)` block, where the sidebar becomes a horizontal strip, the same class
    is restated as `28px` square with `margin:0 9px 0 0`, and `.side-brand`'s
    `align-items` moved from `baseline` to `center` — an image's baseline is its
    bottom edge, so `baseline` would have hung the badge below the word.
  * **Tab icon**: `admin/shell.php` line 761 was
    `<link rel="icon" href="../images/favicon.svg" type="image/svg+xml">`, the
    placeholder drawing, and is now
    `<link rel="icon" type="image/png" sizes="32x32" href="../images/logo/
    favicon-32.png?v=4">` — the same file and the same `?v=` token the 13 public
    pages use. `images/favicon.svg` is now **referenced by nothing in the
    project**; it is left on disk (this file §5 says not to delete files you did
    not create), but it is dead weight.
  * **How this was verified, since you have to be signed in to see the sidebar**
    and the live admin password is off-limits: no login was attempted and no
    credential touched. The served admin HTML was fetched with `curl` (200, and
    it carries the new icon link), both assets were fetched back over HTTP
    (`favicon-32.png?v=4` 200 / 2,862 bytes, `mark.webp?v=3` 200 / 7,676 bytes —
    each matching its size on disk), and the sidebar was measured in a
    same-origin iframe at 360/414/768/1024/1440 carrying the shell's own
    stylesheet and the real `.side-brand` markup. Desktop: panel 268px, badge
    40×40, brand block 111px tall, wordmark right edge 25px inside the panel,
    block not clipped. Narrow: `flex-direction:row`, `align-items:center`,
    badge 28×28, brand block 251px wide inside a 360px viewport, i.e. the strip
    opens on the whole lockup without scrolling to reach it. `document
    .scrollWidth - clientWidth` = **0 at all five widths**, the image reports
    `naturalWidth 120` (it decoded), `php -l admin/shell.php` passes, the console
    is empty, and the diff against the backup is exactly those four hunks.
  Gates after the change: `bake-chrome --check` **0 of 13 page(s) differ from
  includes/** (the admin shell is not baked chrome, so nothing to re-bake),
  `audit.js` **24 broken, 0 of them live** — every broken line names a path
  under `pre-fix-files/`, and this round's backup raised that pile by nothing
  since no HTML page was copied in — **166 warnings**, all of them the
  known shared-title/shared-description reports and the backup-tree noise.
  **What you will actually see**: the sign-in page had no sidebar to change, so
  the only visible difference there is the tab icon; Chrome caches favicons per
  site and ignores `?v=`, so if the tab still shows the old placeholder, open a
  private window before concluding anything.


* **Two named people and six named brands went onto the About page**
  (2026-10-27, seventh round; reversible from
  `pre-fix-files/2026-10-27-about-people-brands/` — `about.html`, `home.html`,
  `index3.html`, `contact.html`, this file, each md5-matched before editing).
  Everything here came from the business in one message; nothing was inferred
  from a photo or another page.
  * **Founder corrected, not replaced.** The About story said "founded by Were
    Stephan in 2014" (the name as given on 2026-10-26). The business has since
    given the full name and a role, so the story now reads "founded by **Stephen
    Were Mbatya** in 2014" and the same person is listed as **Marketing
    Director**. Asked which of those two claims to keep, the answer was *both*,
    so the story keeps the founding sentence and the new Our People card shows
    the title with the line "He founded Kwetu Creations in 2014." Zero
    occurrences of the short form are left anywhere live:
    `grep -rn --include=*.html --include=*.php --exclude-dir=pre-fix-files "Were Stephan" .`
    The four `LocalBusiness` nodes were updated in place and **re-compared**: all
    four still encode to one identical JSON structure, which is the convention
    this project keeps.
  * **New "Our People" section** (`<!-- 02b -->`, between the story and the logo
    mark block): two `.about-person` cards, `Stephen Were Mbatya / Marketing
    Director / He founded Kwetu Creations in 2014.` and `Byuma Daniel / Creative
    Graphics Designer`. Card styling is the expertise card's (same `#1C1C1C`
    ground, same 2px red top rule); the role line is the gold small-caps
    treatment already used elsewhere on the page. Only one person has a note
    line, because only one has a second stated fact - no filler was invented for
    the other.
  * **New brand band** (first built as `<!-- 01b -->` directly under the hero,
    now `<!-- 07b -->` above the closing call to action - moved the same day, see
    the eighth round below): an `<h2>`
    "Brands We Have Worked With" over a six-item `<ul>` — Virah Forex Bureau, ABC
    Capital Bank Uganda, Mountain Inn Hotel, Dahabshiil Money Transfer, Exquisite
    Uganda, NWSC (National Water and Sewerage Corporation). **Names, not logos**:
    no client artwork is on file, so none was drawn or borrowed. The business
    typed all six in capitals; the agreed rendering is title case with the
    abbreviations written out ("UG." became "Uganda", "NWSC( … )" became "NWSC
    (National Water and Sewerage Corporation)"). If you want the capital letters
    back exactly as typed, say so - it is one CSS line.
  * **Client consent is now a live question, not a hypothetical.** These six
    names are on a public page as businesses that Kwetu Creations has worked
    with, on the word of the business. §5 already tells you to get client
    approval for portfolio images and testimonials; the same approval is needed
    for each name here before launch, and a forex bureau, a bank, a money
    transfer company and a national water corporation are the kind of clients
    that have their own rules about being named. Removing one is a one-line
    edit; leave the wrong one up and it is a relationship problem, not a typo.
  * **Layout was measured, not assumed.** The six-across strip was first built
    at the page's usual 950px breakpoint and the probe caught tiles only 138px
    wide at a 1024 viewport, wrapping "Dahabshiil Money Transfer" onto three
    lines; the band now goes six across only above 1200px, three across from
    1200 to 651, and two on phones. Re-measured at 360/414/650/768/1024/1200/1280/1440:
    tiles 150-187px wide and 49-108px tall, **no tile clipped**
    (`scrollWidth <= clientWidth` for all six at every width), section order
    hero → brand band → story → people → mark confirmed by offset position at
    every width, `document.scrollWidth - clientWidth` = **0 at all eight**,
    contrast of the new text measured from the palette (white `#F5F3EF` on the
    `#151515` tile **16.48:1**, gold `#C6A56B` role text on `#1C1C1C` **7.30:1**,
    the 74%-white note line **8.90:1**), the browser console logged nothing but
    the existing shared-chrome message, and all four JSON-LD blocks still
    `json_decode` cleanly.
  * **Deliberately not touched**: the homepage, the portfolio page and the
    services page do not list these brands, the brand names are not in the
    structured data (Google would want each one verifiable, and a client list is
    not a rich result), and the 21 portfolio tiles still carry placeholder
    titles - **these six names are not automatically the titles of those 21
    pictures**, and I have not matched a name to an image without being told
    which image belongs to which client.
  Gates after the change: `bake-chrome --check` **0 of 13 page(s) differ from
  includes/** (no chrome was edited - these blocks live inside `about.html` only,
  while the four structured-data pages were edited in their own JSON-LD),
  `audit.js` **24 broken, 0 of them live** — all 24 name paths under
  `pre-fix-files/`, and this round's five-file backup is why the warning total
  moved from 166 to 174 — with the **18 live warnings unchanged**, none of them
  about the new sections (`about.html: no og:image` and
  `json-ld publishes foundingDate` both pre-date this round; the first is closed
  by `php tools/set-domain.php` once a domain exists, the second is answered by
  this very entry).

* **The brand band moved from under the hero to above the closing call to
  action** (2026-10-27, eighth round; reversible from
  `pre-fix-files/2026-10-27-brands-above-cta/` — `about.html`, this file, both
  md5-matched first). You asked how I saw it, and the honest answer was that the
  band was interrupting the story: the hero promises "We Turn Ideas Into Impact",
  then a row of bank and hotel names came before the visitor had been told
  anything about the studio. The section is now the same markup, relocated to
  `<!-- 07b -->` between "Why Kwetu Creations" and the call to action, so the
  names land as the proof immediately before the ask.
  * **Page order, read off the live DOM:** hero → who we are → our people → our
    mark → philosophy → expertise → our work → how we work → why Kwetu Creations
    → **brands** → call to action.
  * **Its ground swapped, because placement changed what it sits against.** Under
    the hero it had been a `#1C1C1C` strip with `#151515` tiles on the page's
    dark base; in the new slot the section above it ("Why Kwetu Creations") is
    *also* `#1C1C1C`, so the band would have dissolved into it. The two are now
    inverted — band `#151515`, tiles `#1C1C1C` — with the top gold hairline kept
    and the **bottom hairline removed**, because the call to action already draws
    its own and two 1px lines stacked would read as one fat rule. Padding went
    40/44 to 46/50 to suit a block that is followed rather than preceded by a
    large section. Measured contrast after the swap: brand names `#F5F3EF` on the
    `#1C1C1C` tile **15.38:1**, the gold heading on `#151515` **7.82:1**.
  * **Nothing else moved.** The six names, the `<h2>`, the "Our People" section,
    the structured data and the founder's full name are all exactly as the
    seventh round left them; only this one `<section>` block changed position and
    three CSS values changed. Re-measured at 360/414/650/768/1024/1280/1440:
    columns still 2 / 2 / 2 / 3 / 3 / 6 / 6, tiles 150-187 x 49-108 px, **no tile
    clipped and no horizontal document overflow at any width**, console clean.
  * **The trade you accepted:** a visitor who stops at the hero never sees the
    client names now. If that becomes a problem the fix is not to move the whole
    band back — it is to put a single plain sentence under the hero, something
    like "Working with businesses across Kampala since 2014", and leave the names
    where they are.
  Gates after the change: `bake-chrome --check` **0 of 13 page(s) differ from
  includes/**, `audit.js` **24 broken, 0 of them live** (all 24 under
  `pre-fix-files/`; the total warning count moved 174 → 176 purely because this
  round's two-file backup added another pair of duplicate copies for the checker
  to compare), **18 live warnings unchanged**.
