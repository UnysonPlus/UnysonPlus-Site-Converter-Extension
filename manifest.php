<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Site Converter', 'fw' );
$manifest['slug']        = 'unysonplus-site-converter';
$manifest['description'] = __(
	'Bring an AI-generated website into WordPress. The admin home (Unyson+ → Convert) for the AI-site importer: it ingests the artifacts an agent emits per the conversion contract and applies them to the site. This release ships the Media tool — fetch the source site\'s images into the Media Library (de-duped by source URL) from a pasted URL list or by scanning a page. Presets import, menu import, and a one-shot "Convert bundle" are coming next.',
	'fw'
);

$manifest['version']       = '1.0.3';
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
