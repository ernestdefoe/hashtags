const config = require('flarum-webpack-config');

// useExtensions externalises flarum/mentions' modules so we can
// `import … from 'ext:flarum/mentions/…'` for the composer autocomplete.
// mentions is an OPTIONAL dependency: the import lives in its own module and
// is only reached behind a `'flarum-mentions' in flarum.extensions` guard, so
// a forum without mentions installed loads this bundle without error.
module.exports = config({ useExtensions: ['flarum/mentions'] });
