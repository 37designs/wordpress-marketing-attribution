# Spec: contact link tracking (email and phone clicks)

Status: built on branch feature/contact-link-tracking, Oct. 23, 2026. Version 2.1.0.

## Goal

On sites where people email or call instead of filling in a form, an email or phone click is the closest thing to a lead we can measure. Today GA4 only records that a click happened. This adds the same source data the plugin already saves on form entries, so email and phone clicks can be compared with form fills and traced to a channel.

Two parts are in scope:

1. **Source data on every email and phone click**, sent to GTM and on to GA4.
2. **The plugin becomes the only script that rewrites email links:** it adds source lines to the email body and, optionally, swaps in a separate address for visitors who came from an ad.

Out of scope for 2.1.0 (decided Oct. 23): short reference codes mapped to the gclid, a Google Ads upload export, a deduplicated form-submit event, a copy-email event, consent-banner support, and inbox monitoring.

## 1. Source data on email and phone clicks

### How it works

A new front-end script, `assets/contact.js`, enqueued by the plugin on every page (versioned by file modification time), listens for clicks on `a[href^="mailto:"]` and `a[href^="tel:"]` and pushes one event to the data layer:

```js
dataLayer.push({
  event: 'tssc_contact_click',
  tssc: {
    contact_method: 'email',          // email | phone
    link_location: 'header',          // see "Link location" below
    first_source: 'google',           // tssc_ft.s
    first_medium: 'organic',          // tssc_ft.m
    first_landing_page: '/',          // tssc_ft.lp
    session_landing_page: '/quant-works/', // last visit in tssc_path, .lp
    visit_count: 3,                   // tssc_path length (the cookie keeps 8, so 8 means "8 or more")
    days_since_first_visit: 12,       // today minus tssc_ft.t, whole days
    click_number: 1,                  // this visitor's nth email/phone click, from localStorage
    ad_visitor: 'yes'                 // yes | no, see section 2
  }
});
```

The script reads the `tssc_` cookies and falls back to the 1.x `msb_` names, the same way `read_cookie_json()` does on the server. Every value is cut to 100 characters (the GA4 limit). No email addresses, names or gclids are sent.

The clicked page doesn't need a parameter: GA4 records `page_location` on every event.

### Link location

The script assigns `link_location` in this order:

1. A `data-tssc-location` attribute on the link or an ancestor (themes can set this explicitly).
2. Otherwise the first match from a settings list of selector-to-label pairs. Default list:
   - `.sticky-header` → `sticky`
   - `header, .masthead` → `header`
   - `footer` → `footer`
   - `.mfp-content, [role=dialog], #contact-form` → `popup`
   - anything else → `content`

### Click number

`localStorage['tssc_contact_clicks']` holds a counter that goes up by one on each email or phone click. If localStorage isn't available, `click_number` is left out.

### GTM and GA4 setup (per site)

- **Keep the event names** `click_to_email` and `click_to_call`. Google Ads imports those GA4 events as conversion actions, and renaming them would break the Ads import.
- In GTM: replace the two link-click triggers with one Custom Event trigger on `tssc_contact_click`. A lookup variable maps `tssc.contact_method` to `click_to_email` or `click_to_call`. The GA4 event tag sends the parameters above as event parameters.
- Remove the old "Click URL contains mailto:/tel:" triggers in the same publish, or every click counts twice.
- In GA4, register 10 event-scoped custom dimensions: contact_method, link_location, first_source, first_medium, first_landing_page, session_landing_page, ad_visitor, visit_count, days_since_first_visit and click_number. The last three are numbers, but dimensions are simpler to filter than metrics. Data starts from the day they're registered.
- The README gets a "GTM setup" section with these steps and an importable GTM container JSON in `docs/`.

## 2. Email link rewriting

### Replaces the propagate-martech email tracking

Some Three Seven sites run the propagate-martech plugin, whose `email-tracking.js` already rewrites mailto links to add a referral source to the body. Two scripts rewriting the same links will conflict. 2.1.0 takes over that job; when this plugin's email rewriting is on, it dequeues propagate-martech's `prop-email-tracking` script (filter: `tssc_replace_propagate_email_tracking`).

### Email body source lines (setting: off by default)

When on, every mailto link gets these lines added to the body, below three blank lines so the visitor types above them:

```
Source: google / organic
Landing page: /quant-works/
```

Encoding: build the text with `\r\n` line breaks and encode once with `encodeURIComponent`. Don't use `URLSearchParams` (it turns spaces into "+", which mail programs show literally). Keep any existing `subject`, `cc` or `body` parameters.

### Ad visitor address (setting: off by default)

**Setting:** "Address for visitors from ads". This is a free-text email field. It's empty by default, and an empty field means the feature is off.

When it's filled in and the visitor is an ad visitor, the script replaces the site's main address with this address in:

- the `href` of every mailto link that points at the main address, and
- the visible link text, when the text is the address itself (so people who copy it get the ad address too).

The main address is also a setting ("Main contact address"), so the script only touches the address it should.

**Why any address, not just "+ads":** plus-addressing (info+ads@domain) works on Google Workspace and is on by default in Microsoft 365 (an admin can turn it off). Other hosts vary, and an address the server doesn't accept means a lost lead. Leaving the field open lets each site use whatever its mail server supports:

- a plus address, such as `info+ads@example.com`, if a test email to it arrives, or
- a real alias, such as `inquiries@example.com`, that forwards to info@. An alias works on any mail server.

**Before turning it on for a site:** send a test email to the address from an outside account and confirm it lands in the right inbox. Check the domain's MX records: mail routed through a filtering service or a self-hosted server may reject plus addresses.

**Ad visitor definition (decided Oct. 23):** a visitor counts as an ad visitor if any visit in `tssc_path` within the ad click window has a gclid, gbraid or wbraid, or a paid medium (`cpc`, `ppc`, `paid`, `paidsearch`, `paid_social`, `display`, `cpm`). The window is a setting, 30 days by default, which matches the Google Ads default click-through conversion window. A visitor who clicks an ad and comes back directly a week later still gets the ad address; one whose only ad click was 45 days ago doesn't.

**Known limit:** anyone who saves the ad address in their contacts will keep using it, so later emails from them still look ad-sourced. Treat the address as a yes/no flag, not an exact count.

Phone numbers are not swapped. Phone tracking by number would need a call-tracking service and is out of scope.

## Settings added

| Setting | Default | Purpose |
|---|---|---|
| Track email and phone clicks | On | Sends `tssc_contact_click` to the data layer |
| Add source lines to email body | Off | Source and landing page in the mailto body; switches off propagate-martech's email tracking |
| Main contact address | Empty | The address the swap looks for |
| Address for visitors from ads | Empty (off) | Swapped in for ad visitors |
| Ad click window | 30 days | How far back an ad click counts |
| Link location selectors | List above | Labels for `link_location` |

## Testing

1. Each site location (header, sticky, footer, popup, page body) produces the right `link_location` in GTM Preview.
2. A visit with `?gclid=test123`: `ad_visitor` is yes, the mailto `href` and visible text show the ad address, and nothing changes when the setting is empty.
3. Mail drafts open cleanly, with no `%20` or "+" codes, in Gmail (web), Outlook (desktop and web) and Apple Mail.
4. Existing subject/body parameters on a link survive.
5. GA4 DebugView shows `click_to_email` and `click_to_call` with all parameters, and each click is counted once.
6. With cookies blocked, clicks still send `contact_method` and `link_location` without errors.

## Effort

About 6 hours: 3 for the script and settings, 1 for GTM/GA4 setup and the container export, 2 for cross-client testing. Setup on each site after that is about 30 minutes.

## Decisions

1. Ad visitor = an ad click within the ad click window (30 days by default). Decided Oct. 23, 2026.
