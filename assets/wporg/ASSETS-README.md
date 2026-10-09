# WordPress.org Plugin Assets

Place the following image files in this directory before running deploy-to-wporg.sh.
They are uploaded to the `/assets/` folder of the SVN repository (NOT trunk).

## Required files

| File | Size | Purpose |
|---|---|---|
| `icon-128x128.png` | 128×128 px | Plugin icon (standard) |
| `icon-256x256.png` | 256×256 px | Plugin icon (retina) |
| `banner-772x250.png` | 772×250 px | Plugin directory banner |
| `banner-1544x500.png` | 1544×500 px | Plugin directory banner (retina) |

## Optional screenshot files

Named `screenshot-1.png` through `screenshot-8.png`, matching the
descriptions in the `== Screenshots ==` section of readme.txt.

| File | Contents |
|---|---|
| `screenshot-1.png` | Dashboard overview with SEO health score |
| `screenshot-2.png` | Meta tags editor in Gutenberg sidebar |
| `screenshot-3.png` | Redirect manager with loop detection |
| `screenshot-4.png` | Technical SEO audit results |
| `screenshot-5.png` | Image SEO dashboard |
| `screenshot-6.png` | Schema output with live validation |
| `screenshot-7.png` | Keyword rank tracker chart |
| `screenshot-8.png` | Content gap analysis |

## Notes
- PNG or JPG accepted; PNG preferred for icons, JPG for banners
- Do NOT commit screenshots to trunk — only to /assets/
- Max file size: 1 MB per asset
- The icon and banner files in this directory are the correct directory pixel sizes.
- screenshot-1.png through screenshot-8.png, here and in assets/screenshots/, are the same design mockups. They show the previous name, AmEveryWhere, and a health-score dashboard this plugin does not ship. Do not upload them. readme.txt does not list screenshots until a logged-in Holvek admin is captured.
