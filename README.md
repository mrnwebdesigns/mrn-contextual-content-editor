# MRN Contextual Content Editor

Adds a logged-in front-end contextual menu for editors. Hover content, choose **Edit this**, and the plugin opens the best matching Classic Editor field or ACF field on the post edit screen.

## What It Does

- Runs only for logged-in users who can edit the current singular post.
- Adds a small front-end hover menu with direct links to the editor.
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

## Notes

- The plugin does not save content from the front end. It only opens the editor to the closest known field.
- Repeater and flexible content subfields can be matched by value. Without exact markup, the admin focus scrolls to the matching field key/name, not a guaranteed row instance.
- ACF is optional. If ACF is unavailable, core post fields and featured image matching still work.
