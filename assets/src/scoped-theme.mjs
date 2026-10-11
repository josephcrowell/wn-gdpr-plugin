import plugin from 'tailwindcss/plugin';
import daisyTheme from 'daisyui/theme';

// Keep daisyUI's native default/prefersdark handling, but contain its normally
// global [data-theme] selectors to plugin roots. This runs at build time only.
export default plugin.withOptions((options) => (context) => {
    function scope(styles) {
        return Object.fromEntries(Object.entries(styles).map(([selector, declarations]) => {
            if (selector.startsWith('@')) return [selector, scope(declarations)];

            return [selector.replace(/(^|,)(\[data-theme=[^\]]+\])/g, '$1.gdpr-ui$2'), declarations];
        }));
    }

    daisyTheme({
        ...options,
        // A host's explicit daisyUI theme supplies inherited variables instead.
        root: '.gdpr-ui:not(:where([data-theme], [data-theme] *))',
    }).handler({
        ...context,
        addBase: (styles) => context.addBase(scope(styles)),
    });
});
