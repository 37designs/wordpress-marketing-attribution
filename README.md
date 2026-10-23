# Three Seven Gravity Forms Source Capture

A WordPress plugin by [Three Seven](https://threesevenmarketing.com). It saves where each inquiry came from on its Gravity Forms entry:

1. The first visit's source and landing page
2. Every visit's source in order, up to 8 visits within 90 days
3. The most recent Google Ads click ID, ready for offline conversion uploads
4. The visitor's own answer to "How did you hear about us?" (optional)

Gravity Forms on its own records only the page an entry was submitted from. Someone who clicks an ad, reads three pages and submits from a contact page shows no ad source at all.

## Install (about 30 minutes per site)

1. Download `gf-source-capture.zip` from the [latest release](https://github.com/37designs/gf-source-capture/releases/latest). Upload it under Plugins > Add New > Upload Plugin and activate it.
2. Open Settings > Source Capture. Check the cookie domain, add any payment or sign-in domains the site redirects through, edit the "How did you hear about us?" answers if needed, and save.
3. Copy the snippet from that page into a Google Tag Manager Custom HTML tag. Fire it on all page views, with the same consent condition the site's other marketing tags use. Publish the container.
4. In the forms table, tick each form that counts as an inquiry, then click "Add fields to ticked forms."
5. Run the tests below.

The GTM tag holds a copy of the script. Re-paste the snippet and republish the container after a plugin update that changes `assets/tracker.js`, or after changing the cookie domain or ignore list.

## How it works

- **Cookie script.** On each page view, the script checks whether the visitor arrived from outside the site: with ad or UTM parameters, from another site, or with no referrer at the start of a session. If so, it records the visit in first-party cookies (`tssc_ft` for the first visit, `tssc_path` for the visit list). Clicks within the site, page reloads and sign-in or payment redirects are skipped.
- **Mediums.** Each visit gets a source and a medium: `cpc` for Google Ads clicks, `organic` for search engines, `ai-assistant` for ChatGPT, Perplexity, Claude, Gemini and Copilot, `social` for LinkedIn, Facebook, Instagram, X, YouTube, Reddit and Bluesky, `referral` for other sites, and `(none)` for direct visits. UTM parameters override all of these.
- **Filling the fields.** On pages with a tagged form, `assets/fill.js` copies the cookies into the hidden fields in the visitor's browser. It runs again when the visitor focuses a field and when they submit. Page caching can't mix up visitors, because each browser fills its own copy of the form.
- **Server check.** At submission, the plugin re-reads the cookies if they reach WordPress. If a host's cache layer strips them, it uses the values the browser posted. It keeps only known keys, trims each value, and works out the gclid and landing page from the visit list.
- **Notification emails.** In `{all_fields}`, the first touch and touch path show as readable lines, for example "2026-10-07 14:02 UTC · google / cpc · landed on /services/". The entry keeps the JSON.

## Field reference

The plugin finds fields by their dynamic population parameter name. You can add them by hand instead of using the settings page.

| Field | Type | Parameter name |
|---|---|---|
| First touch | Hidden | `tssc_first_touch` |
| Touch path | Hidden | `tssc_touch_path` |
| Google click ID | Hidden | `tssc_gclid` |
| Landing page | Hidden | `tssc_landing_page` |
| How did you hear about us? | Drop Down | `tssc_heard_about` |
| Tell us more (optional) | Single Line Text | `tssc_heard_about_detail` |

## Consent

The cookies need the same consent as other marketing cookies. With GTM delivery, the consent banner controls the tag. With "load from plugin" delivery, the consent tool has to block the script itself; use the `tssc_script_tag` filter to change the opening tag, for example to `<script type="text/plain" data-category="marketing">`. Entries from visitors who decline will have empty source fields, but their "How did you hear about us?" answer is still saved.

## Tests

Use a private browser window for each test, then delete the test entries.

1. Open `/?utm_source=test&utm_medium=email&utm_campaign=spec`, browse three pages and submit a form. First touch shows `test / email` and the landing page is `/`.
2. Open `/?gclid=TEST123` and browse to another page. In the DevTools console (frame set to "top"), `document.querySelector('.tssc-gclid input').value` returns `TEST123`. Submit, and the entry's Google click ID reads `TEST123`.
3. Repeat test 1. In the same window, search Google for the brand, click the organic result and submit. First touch still says `test`, and the touch path lists both visits.
4. Reload the thank-you page after a submission. The touch path gains no new visit.

## Upgrading from Source Capture 1.x

Version 2.0 renamed the plugin and every `msb_` prefix to `tssc_`. It migrates on its own:

- **Settings and forms:** on the first admin page load after activation, the plugin copies the 1.x settings and renames the `msb_` field parameters on every form. Field IDs stay the same, so existing entries keep their values.
- **Visitor cookies:** the new cookie script moves each visitor's `msb_` cookies to `tssc_` on their next page view, keeping the first visit's original expiry.
- **In between:** until the GTM snippet is replaced, `fill.js` and the server still read the old `msb_` cookies, so no submissions lose their source.

Steps:

1. Upload and activate `gf-source-capture.zip` while the old plugin is still active.
2. Open Settings > Source Capture and copy the snippet. In GTM, replace the old tag's contents with it and publish the container.
3. Deactivate and delete the old 1.x "Source Capture" plugin.
4. Run test 2.

## Files

- `gf-source-capture.php`: the plugin
- `assets/tracker.js`: the cookie script (the plugin fills in the site's domain and ignore list)
- `assets/fill.js`: copies the cookies into the form's hidden fields
- `uninstall.php`: deletes the settings on uninstall and leaves forms and entries alone

## Updates

Sites update from this repository's GitHub releases through the normal WordPress Plugins screen (version 2.1.0 and later). Turn on "Enable auto-updates" for the plugin to update without clicking.

To release a new version:

1. Bump `Version:` in the plugin header and `const VERSION` in `gf-source-capture.php` to the same number.
2. Commit and push to `main`.
3. Tag and push: `git tag v2.1.1 && git push origin v2.1.1`.

The release workflow checks that the tag matches both version numbers, builds `gf-source-capture.zip` and publishes the release. Sites see the update within about 6 hours (the GitHub check is cached).

