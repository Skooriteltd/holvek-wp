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
| `screenshot-1.png` | Holvek dashboard with local counts |
| `screenshot-2.png` | Holvek panel in the block editor |
| `screenshot-3.png` | Redirects Manager |
| `screenshot-4.png` | Technical SEO audit before a scan |
| `screenshot-5.png` | Image SEO |
| `screenshot-6.png` | Schema markup templates |
| `screenshot-7.png` | Keyword rank tracker |
| `screenshot-8.png` | XML sitemap settings |

## Notes
- PNG or JPG accepted; PNG preferred for icons, JPG for banners
- Do NOT commit screenshots to trunk — only to /assets/
- Max file size: 1 MB per asset
- The icon files are 128 and 256 pixels. They do not contain a product name and were left unchanged.
- The banner files are 772 by 250 and 1544 by 500. They use the same mark and map, with the word Holvek.
- screenshot-1.png through screenshot-8.png, here and in assets/screenshots/, are captures from the Holvek admin on the local UAT site. readme.txt names those files.
