const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        editor: path.resolve(__dirname, 'src/editor/index.tsx'),
        // React admin app; also emits assets/css/admin.css from its import.
        admin: path.resolve(__dirname, 'src/admin/index.tsx'),
        'tailwind-compiler': path.resolve(__dirname, 'src/tailwind/compiler.ts'),
        // React field boxes in the post editor; also emits assets/css/fields-box.css.
        'fields-box': './src/fields/box/index.tsx',
    },
    output: {
        path: path.resolve(__dirname, 'assets/js'),
        filename: '[name].js',
        // Lazy chunks (admin screens) load by URL with no ?ver= query, so
        // the content hash is what busts the browser cache on update.
        chunkFilename: '[name].[contenthash:8].js',
    },
    experiments: {
        ...(defaultConfig.experiments || {}),
        // Required so the Tailwind v4 engine's WebAssembly bundles can load.
        asyncWebAssembly: true,
    },
    resolve: {
        ...defaultConfig.resolve,
        extensions: ['.ts', '.tsx', '.js', '.jsx'],
        alias: {
            '@': path.resolve(__dirname, 'src'),
        },
        // lightningcss-wasm's loader tries `import('fs')` and falls back to
        // `fetch()` in the browser. Stub `fs` to false so webpack doesn't warn
        // about the unresolved Node module and the browser cleanly uses fetch.
        fallback: {
            ...(defaultConfig.resolve && defaultConfig.resolve.fallback),
            fs: false,
        },
    },
    module: {
        ...defaultConfig.module,
        rules: [
            // Filter out default CSS rules to replace with our own
            ...defaultConfig.module.rules.filter(
                (rule) => !rule.test || !rule.test.toString().includes('css')
            ),
            {
                test: /\.tsx?$/,
                use: [
                    {
                        loader: 'ts-loader',
                        options: {
                            transpileOnly: true,
                        },
                    },
                ],
                exclude: /node_modules/,
            },
            // The browser Tailwind v4 engine imports the bundled v4 CSS files
            // (theme/preflight/utilities/index) as raw source strings, which it
            // feeds to compile() at runtime. These must NOT go through
            // css-loader/PostCSS — emit their literal text instead.
            {
                test: /[\\/]node_modules[\\/]tailwindcss[\\/].*\.css$/,
                type: 'asset/source',
            },
            // Project styles: PostCSS with Tailwind 4 (see postcss.config.js).
            {
                test: /\.css$/,
                exclude: /[\\/]node_modules[\\/]tailwindcss[\\/]/,
                use: [MiniCssExtractPlugin.loader, 'css-loader', 'postcss-loader'],
            },
        ],
    },
    plugins: [
        // RtlCssPlugin is dropped too: it wrote `<name>-rtl.css` next to the
        // scripts in assets/js, and nothing registered an RTL stylesheet.
        ...defaultConfig.plugins.filter(
            (plugin) =>
                plugin.constructor.name !== 'MiniCssExtractPlugin' &&
                plugin.constructor.name !== 'RtlCssPlugin'
        ),
        new MiniCssExtractPlugin({
            filename: '../css/[name].css',
        }),
    ],
};
