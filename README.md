# Divi Restore Theme Builder Templates

A WordPress plugin for recovering deleted or missing Divi Theme Builder templates and template parts. Compatible with **Divi 4** and **Divi 5**.

## Background

Divi's Theme Builder uses four custom post types to store its data:

| Post type | Description |
|---|---|
| `et_template` | The template container (holds assignment rules and links to its parts) |
| `et_header_layout` | Custom header template part |
| `et_body_layout` | Custom body template part |
| `et_footer_layout` | Custom footer template part |

All data is stored in the WordPress database. When a template or template part is deleted through the Divi Theme Builder interface, Divi does **not** trash or delete the post — it stamps it with a `_et_theme_builder_marked_as_unused` meta value (a timestamp). After 7 days, Divi's cleanup job moves the post to the WordPress trash. This plugin intercepts that process and lets you restore items before they are lost.

## Requirements

- The **Divi theme** (or a Divi child theme) must be installed and active. The plugin will not activate without it.

## Install the Plugin

1. Download the plugin zip file
2. In your WordPress Dashboard go to **Plugins > Add New**
3. Click **Upload Plugin** at the top
4. Choose the zip file and activate

## How to Use

Once activated, navigate to **Divi > Restore TB Templates** in your WordPress Dashboard. The plugin has three tabs.

---

### Tab 1 — Templates

Lists all `et_template` posts that have been deleted or marked as unused by Divi.

Each row shows:
- **Template title** and ID
- **Assigned to** — which pages or post types the template was applied to (parsed into readable labels)
- **Linked parts** — which header, body, and footer layout parts were linked to this template, and whether each part is also deleted
- **Marked unused** — the date Divi marked it for deletion, plus a countdown showing how many days remain before it is auto-trashed
- **Status** — whether the post is still Published or has already been moved to Trash

**Restore** re-links the template to the root Theme Builder post, removes the unused mark, and restores any linked template parts at the same time.

**Restore All** restores every deleted template and its linked parts in one click.

> After restoring, open the Divi Theme Builder to verify the templates are correctly assigned. In Divi 5, opening the Theme Builder will fully re-establish any internal connections managed by Divi's interface.

---

### Tab 2 — Template Parts

Lists all `et_header_layout`, `et_body_layout`, and `et_footer_layout` posts that have been deleted or marked as unused — including parts that were removed individually from an otherwise active template. Parts are grouped by type (Headers, Bodies, Footers).

Each row shows the part title, type, the date it was marked unused, and its current post status.

**Export JSON** downloads the template part as a Divi-compatible `.json` file. The export includes:
- The part's full layout content
- Any **global presets** referenced by modules in the part
- Any **global colours** referenced in the layout or presets
- The part's custom CSS (`_et_pb_custom_css`)

To recover the part, import the downloaded file via **Divi Theme Builder → Import** (the upload icon in the Theme Builder header). Divi will recreate the part and you can then assign it to the appropriate template.

---

### Tab 3 — Revisions

WordPress automatically saves revisions every time a template part is edited. This tab lets you browse and restore those revisions — useful when you want to roll back a change rather than recover a deleted part.

**Overview** lists every template part that has at least one revision, showing the part type, its parent template (if linked), the total revision count, and the date of the most recent revision.

Click **View Revisions** to open the comparison view directly on the most recent revision.

**The comparison view** shows a side-by-side diff:
- **Left column (Previous Revision)** — the older state being compared against
- **Right column (This Revision)** — the content stored in the selected revision

The diff highlights exactly which blocks or lines were added or removed. Content is shown as raw Divi block markup (Divi 5) or shortcode (Divi 4) — a visual preview is not available, but the diff is sufficient to identify what changed.

**Navigating revisions** — at the top of the comparison view there is a timeline with three controls:
- **← Previous** — step back to an older revision
- **Slider** — jump directly to any revision without stepping through each one
- **Next →** — step forward to a newer revision

The position indicator below the slider shows which revision you are viewing (e.g. *Revision 3 of 7*) along with its save date and author.

Click **Restore This Revision** to replace the template part's current content with the content from the selected revision. If the part was also marked as unused, the unused mark is cleared at the same time.

---

## Multisite

Activate the plugin at the **sub-site level**, not at the Network level.

---

## Known Compatibility Issues

### WPML / Polylang and other translation plugins

Translation plugins intercept WordPress post queries for custom post types, which can prevent the plugin from reading or restoring template data correctly.

**Recommended approach:**
1. Deactivate the translation plugin
2. Use this plugin to restore the templates
3. Reactivate the translation plugin

### Divi 5

After restoring templates or template parts in Divi 5, always open the **Divi Theme Builder** to verify everything is correctly linked. Divi 5 manages some template associations through its own interface layer — opening the Theme Builder triggers a re-sync that finalises the connections.
