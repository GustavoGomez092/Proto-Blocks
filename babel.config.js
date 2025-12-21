module.exports = (api) => {
    api.cache(true);

    return {
        presets: [
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
