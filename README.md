# QBMBOT

AI chat widget and Contact Form 7 email auto-responder for WordPress. Built to work with Elegant Themes Divi 4 and Divi 5 without relying on Divi module APIs.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- Contact Form 7 (optional; required only for enquiry auto-replies)
- OpenAI and/or Anthropic API key (bring your own)

## Install

1. Copy this folder to `wp-content/plugins/qbmbot` (or zip the plugin directory and upload via **Plugins → Add New**).
2. Activate **QBMBOT**.
3. Open **QBMBOT** in wp-admin and configure providers, FAQ preloads, spam limits, appearance, and CF7 field mapping.

## Features

- Floating chat window fixed to the **bottom left**
- **Business profile** so answers stay on this SME / trades business (no general DIY, no competitors)
- **WordPress content sourcing** from published pages/posts (pinned + search)
- Preloaded questions with optional display answers and **AI instructions**
- Multi-provider AI: **OpenAI** and **Anthropic** (selectable active provider)
- Spam / bot heuristics, honeypot, timing checks, optional Akismet
- Rate limits: per IP, per session, and site-wide daily AI cap
- Contact Form 7 **email-only** auto-replies after `wpcf7_mail_sent`
- Appearance controls (colors, font, radius, offsets, custom CSS)
- Usage logs in the admin

## Business scope (SME / trades)

Under **Business & Content**, set:

- Business name, trade, services, service area, key facts
- Topics to refuse and an off-topic handoff message

Every AI call includes mandatory scope rules: answer only about this business, use site content / FAQ / profile, do not invent prices or recommend other companies, and refuse off-topic questions with your handoff message.

## WordPress site content

Enable **Source context from this WordPress site** to ground replies in published content:

1. Choose post types (pages and/or posts)
2. **Pin** About / Services / Areas Covered pages so they are always in context
3. Optionally exclude pages that should never be used
4. Tune search result count and character budgets

On each chat message or CF7 enquiry, QBMBOT searches the site for relevant content and injects plain-text excerpts into the prompt (before the LLM is called).

## Chat widget

When enabled under **General**, the widget mounts in `wp_footer` and calls:

`POST /wp-json/qbmbot/v1/chat`

Requests require a valid `wp_rest` nonce. API keys never leave the server.

Suggested questions are loaded from **FAQ / Preloads**. If a FAQ has a display answer and **no** AI instructions, the static answer is returned without calling the LLM. Otherwise the global prompt plus that item’s AI instructions are sent to the active provider.

## Contact Form 7

1. Enable CF7 auto-reply under **General**.
2. On the **CF7** tab, map field names (defaults: `your-name`, `your-email`, `your-message`).
3. Optionally restrict to specific forms (empty selection = all forms).
4. Configure subject, From headers, and fallback body.
5. Optionally enable **Ask for more details** / **Ask for photos** so covered-service enquiries invite a reply with missing info and images when useful.

On successful mail send, QBMBOT runs spam + rate checks. If blocked, it can send the static fallback (no AI). If allowed, it generates a plain-text reply with the active AI provider and sends it via `wp_mail`. For in-scope service jobs, replies can ask the customer to email back relevant details and photos.

Auto-replies do **not** open or update the chat widget.

## Spam and throttling

Before any AI call, QBMBOT scores the request (length, links, keywords, honeypot, open timing, user-agent, optional Akismet). Scores at or above the threshold are blocked and logged.

Throttling (defaults):

- 10 requests per IP / 10 minutes
- 30 messages per session / day
- 500 AI calls site-wide / day

Tune these under **Spam & Limits**. Exceeding limits returns HTTP 429 for chat and skips AI for CF7.

## Appearance / Divi

Style the widget under **Appearance**. CSS variables are injected on `.qbmbot-root`:

- `--qbmbot-primary`, `--qbmbot-bg`, `--qbmbot-text`, `--qbmbot-panel`
- `--qbmbot-font`, `--qbmbot-radius`, `--qbmbot-offset-x`, `--qbmbot-offset-y`

**Divi 4 & 5:** the widget uses standard `wp_enqueue_scripts` / `wp_footer` with no Divi script dependencies or `et_pb_*` modules, so it works on classic Divi layouts and Divi 5 pages. Use Custom CSS if a theme stacking context needs a higher z-index (default is `999999`).

## Uninstall

By default, uninstall leaves options and logs in place. Enable **Delete settings, FAQ, and logs when uninstalling** under **General** before removing the plugin if you want a clean removal.

## Development layout

```
qbmbot.php
includes/          # PHP services (settings, REST, CF7, spam, AI providers)
admin/             # Settings UI
public/            # Chat widget JS/CSS
uninstall.php
```

## License

GPL-2.0-or-later
