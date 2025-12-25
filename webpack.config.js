const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        editor: path.resolve(__dirname, 'src/editor/index.tsx'),
        admin: path.resolve(__dirname, 'src/admin/index.ts'),
    },
    output: {
        path: path.resolve(__dirname, 'assets/js'),
        filename: '[name].js',
    },
    resolve: {
        ...defaultConfig.resolve,
        extensions: ['.ts', '.tsx', '.js', '.jsx'],
        alias: {
            '@': path.resolve(__dirname, 'src'),
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
            // CSS processing with PostCSS and Tailwind
            {
                test: /\.css$/,
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
