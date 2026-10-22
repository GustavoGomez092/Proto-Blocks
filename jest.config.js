// @wordpress/scripts 37 no longer supplies Jest defaults, so they live here.
module.exports = {
    testEnvironment: 'jsdom',
    testMatch: ['<rootDir>/src/**/__tests__/**/*.test.[jt]s?(x)'],
    moduleNameMapper: { '\\.css$': '<rootDir>/jest.style-mock.js' },
    // scripts/*.test.mjs use node:test and run through `npm run test:scripts`.
    testPathIgnorePatterns: ['/node_modules/', '<rootDir>/vendor/', '<rootDir>/scripts/'],
};
