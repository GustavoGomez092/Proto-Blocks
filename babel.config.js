module.exports = (api) => {
    const isTest = process.env.NODE_ENV === 'test';

    // The preset list differs between test and build, so cache must vary by env.
    api.cache.using(() => process.env.NODE_ENV);

    return {
        presets: [
            // Jest needs ES modules transformed to CommonJS. The webpack build
            // handles its own module resolution, so preset-env is test-only and
            // the build preset list is left untouched.
            ...(isTest
                ? [['@babel/preset-env', { targets: { node: 'current' } }]]
                : []),
            '@babel/preset-typescript',
            [
                '@babel/preset-react',
                {
                    // Use classic JSX runtime (React.createElement)
                    runtime: 'classic',
                },
            ],
        ],
        plugins: [
            '@babel/plugin-transform-runtime',
        ],
    };
};
