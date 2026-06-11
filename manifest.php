<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Site Converter', 'fw' );
$manifest['slug']        = 'unysonplus-site-converter';
$manifest['description'] = __(
	'Bring an AI-generated website into WordPress. The admin home (Unyson+ → Convert) for the AI-site importer: it ingests the artifacts an agent emits per the conversion contract and applies them to the site. Ships three tools so far: the Media tool — fetch the source site\'s images into the Media Library (de-duped by source URL) from a pasted URL list or by scanning a page — the Styling Presets importer, which applies a presets export (palette, font sizes, button colors, spacing/gap scales) into the theme-independent preset store in one step, and the Menu importer, which builds WordPress nav menus from the source navigation and assigns them to the theme\'s header / footer menu locations. A one-shot "Convert bundle" is coming next.',
	'fw'
);

$manifest['version']       = '1.0.6';
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Site-Converter-Extension';
$manifest['display']       = true;
$manifest['standalone']    = true;

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
