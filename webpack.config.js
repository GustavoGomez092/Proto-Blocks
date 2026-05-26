const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        editor: path.resolve(__dirname, 'src/editor/index.tsx'),
        admin: path.resolve(__dirname, 'src/admin/index.ts'),
        'tailwind-compiler': path.resolve(__dirname, 'src/tailwind/compiler.ts'),
    },
    output: {
        path: path.resolve(__dirname, 'assets/js'),
        filename: '[name].js',
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
            // css-loader/PostCSS — emit their literal text instead. The v4
            // engine is installed under the `tailwindcss-v4` npm alias so it
            // does not collide with the project's existing Tailwind v3 PostCSS
            // pipeline (see package.json).
            {
                test: /[\\/]node_modules[\\/]tailwindcss-v4[\\/].*\.css$/,
                type: 'asset/source',
            },
            // CSS processing with PostCSS and Tailwind (v3, project styles).
            {
                test: /\.css$/,
                exclude: /[\\/]node_modules[\\/]tailwindcss-v4[\\/]/,
                use: [
                    MiniCssExtractPlugin.loader,
                    'css-loader',
                    {
                        loader: 'postcss-loader',
                        options: {
                            postcssOptions: {
                                plugins: [
                                    require('tailwindcss'),
                                    require('autoprefixer'),
                                ],
                            },
                        },
                    },
                ],
            },
        ],
    },
    plugins: [
        ...defaultConfig.plugins.filter(
            (plugin) => plugin.constructor.name !== 'MiniCssExtractPlugin'
        ),
        new MiniCssExtractPlugin({
            filename: '../css/[name].css',
        }),
    ],
};
