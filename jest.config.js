// @wordpress/scripts 37 no longer supplies Jest defaults, so they live here.
module.exports = {
    testEnvironment: 'jsdom',
    testMatch: ['<rootDir>/src/**/__tests__/**/*.test.[jt]s?(x)'],
    moduleNameMapper: {
        '\\.css$': '<rootDir>/jest.style-mock.js',
        '^@/(.*)$': '<rootDir>/src/$1',
    },
    setupFilesAfterEnv: ['<rootDir>/jest.setup.ts'],
    // These ship ES modules that Babel must transform for Jest.
    transformIgnorePatterns: ['/node_modules/(?!(lucide-react|sonner)/)'],
    // scripts/*.test.mjs use node:test and run through `npm run test:scripts`.
    testPathIgnorePatterns: ['/node_modules/', '<rootDir>/vendor/', '<rootDir>/scripts/'],
};
