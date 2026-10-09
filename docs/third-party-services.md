# Third-Party Services & Data Disclosures

Holvek operates under a strict data minimization philosophy. The plugin itself does **not** collect telemetry, track users, or "phone home" to our servers for general usage statistics.

However, to provide advanced SEO functionality, Holvek acts as a bridge to several external APIs. These APIs are entirely optional and are only invoked if a Site Administrator explicitly configures them.

If you utilize these features, you should disclose them in your site's Privacy Policy. Holvek automatically injects a template for this into the WordPress Privacy Policy Guide.

## Services That May Receive Data

| Feature | External Service | Data Transmitted | Privacy Policy |
| :--- | :--- | :--- | :--- |
| **LLM Writing Assistant** | OpenAI API (api.openai.com) | When explicitly triggered by an editor, the current post title, meta description, or content excerpts are sent to generate suggestions. | [OpenAI Privacy](https://openai.com/privacy) |
| **LLM Writing Assistant** | Anthropic API (api.anthropic.com) | Same as above, if Anthropic is selected instead of OpenAI. | [Anthropic Privacy](https://www.anthropic.com/privacy) |
| **Rank Tracking** | SerpApi (serpapi.com) | Your configured focus keywords are sent to retrieve SERP rankings. | [SerpApi Privacy](https://serpapi.com/privacy) |
| **PageSpeed Dashboard** | Google PageSpeed API | The URLs of your posts/pages are transmitted to fetch Core Web Vitals. | [Google Privacy](https://policies.google.com/privacy) |
| **Search Console** | Google Search Console API | Read-only OAuth2 access is used to fetch impressions, clicks, and CTR data. No data is written to Google. | [Google Privacy](https://policies.google.com/privacy) |
| **Instant Indexing** | IndexNow (`api.indexnow.org`, Bing, Yandex, Seznam) | After an administrator turns IndexNow on and saves a key, published URLs are queued and sent in small batches only to those allow-listed endpoints. Holvek does not create a key, and nothing is sent during the publish request. | [IndexNow Privacy](https://www.indexnow.org/) |
| **Instant Indexing** | Google Indexing API | If enabled, published URLs are sent to Google to accelerate crawling. | [Google Privacy](https://policies.google.com/privacy) |
| **Holvek API** | Holvek (api.holvek.com) | If you connect Holvek, requests go only to `https://api.holvek.com`. The settings screen cannot change that host. | [Holvek Privacy](https://holvek.com/privacy) |

## Data Retention & Storage
- **Stored credentials:** When the PHP OpenSSL extension is available, API keys, access tokens, and the Holvek license string are encrypted in the WordPress options table. The key is derived from the option `holvek_encryption_salt`. Settings export leaves out that salt, indexing credentials, the IndexNow key, API keys, and OAuth secrets. Import does not write them. A file that contains an unrecognized setting is rejected before any value is saved. A full database copy still contains the salt and the ciphertext, so that copy can be decrypted. Encryption does not protect a stolen database. If OpenSSL is missing, Holvek shows an administrator notice and does not save those secrets.
- **Ollama:** The default address is `http://localhost:11434` on this server, and that traffic stays on the machine. If an administrator confirms a remote http or https host, Holvek sends the prompt, which can include the post title and post content, to that host. A settings import cannot switch Ollama to a remote host.
- **404 Logs:** Missing URLs are logged in your local WordPress database to assist in redirect creation. By default, these logs are automatically purged after 90 days.
- **Audit Logs:** SEO setting changes are recorded in a local audit log for accountability. These are automatically purged after 365 days.
