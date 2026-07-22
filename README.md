# MRN Contextual Content Editor

Adds a logged-in front-end contextual menu for editors. Hover content to reveal a small cursor-following **Edit** pill, click the content, and choose the best matching Classic Editor field or ACF field on the post edit screen.

## What It Does

- Runs only for logged-in users who can edit the current singular post.
- Adds a compact cursor-following edit affordance that expands only after the hovered content is clicked.
- Matches selected or hovered text against the post title, excerpt, Classic Editor content, featured image, and ACF values.
- Supports images and links by matching attachment URLs, featured images, and ACF image/link fields.
- Opens `post.php?action=edit` with query parameters that focus and highlight the matching core editor field or ACF field.

## Exact Field Markup

For reliable targeting in theme-controlled markup, add the helper attributes to the rendered element:

```php
echo '<h2' . mrn_contextual_content_editor_attrs(
	array(
		'post_id'  => get_the_ID(),
		'acf_key'  => 'field_abc123',
		'acf_name' => 'hero_heading',
		'label'    => 'Hero heading',
	)
) . '>' . esc_html( $heading ) . '</h2>';
```

Core fields are supported too:

```php
echo '<h1' . mrn_contextual_content_editor_attrs(
	array(
		'post_id' => get_the_ID(),
		'core'    => 'title',
		'label'   => 'Post title',
	)
) . '>' . esc_html( get_the_title() ) . '</h1>';
```

Allowed `core` values are `title`, `content`, `excerpt`, and `thumbnail`.

## Row-Scoped Detection

Shared template renderers can scope fuzzy matching to one flexible-content or
repeater row without marking every field. The helper adds attributes to the
first existing element by default and returns public markup unchanged for
visitors who cannot edit the post. If an anchor or other helper element comes
first, use `target_class` to identify the actual content root:

```php
$markup = mrn_contextual_content_editor_inject_attrs(
	$markup,
	array(
		'post_id'          => get_the_ID(),
		'target_class'     => 'mrn-content-builder__row',
		'scope_field_key'  => 'field_mrn_page_content_rows',
		'scope_field_name' => 'page_content_rows',
		'scope_row'        => $row_index,
		'scope_layout'     => 'hero',
	)
);
```

The resolver ranks matches from that row first, includes the exact row path in
the resulting WordPress admin link, and retains page-wide alternatives for the
result carousel. Exact field attributes still take priority when a template
supplies them.

Value-based matching excludes non-rendered metadata such as `anchor`,
`internal_name`, and fields labeled “admin use only.” Sites can extend the excluded-name list with
the `mrn_contextual_content_editor_excluded_fuzzy_acf_field_names` filter.

## Notes

- The plugin does not save content from the front end. It only opens the editor to the closest known field.
- Context Edit is off by default. Press `Ctrl+Shift+E` on Windows/Linux or `Command+Shift+E` on macOS to toggle it. The chosen state persists while navigating in the same browser tab.
- Matching fields appear one at a time with the best match first. Wheel, trackpad, touch, and arrow/page keys move between complete result cards.
- Clicking a different page element while the panel is open immediately retargets the panel and resolves that element.
- Rich-text matches carry the clicked text into the WordPress editor and select that exact text inside TinyMCE or the textarea when available.
- Repeater and flexible-content subfields can be row-scoped by shared renderers. Without row scope or exact markup, matching falls back to the full ACF value tree.
- ACF is optional. If ACF is unavailable, core post fields and featured image matching still work.
