// Karma configuration.
//
// The app previously had no karma.conf at all and relied on CLI defaults,
// which launch a windowed Chrome — unusable in CI or over SSH. This adds a
// headless launcher and machine-readable output.

const fs = require('fs');

// Locate Chrome unless CHROME_BIN is already set (CI usually sets it).
if (!process.env.CHROME_BIN) {
  const candidates = [
    process.env.CHROME_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
  ].filter(Boolean);

  const found = candidates.find((p) => {
    try {
      return fs.existsSync(p);
    } catch {
      return false;
    }
  });

  if (found) {
    process.env.CHROME_BIN = found;
  }
}

module.exports = function (config) {
  config.set({
    basePath: '',
    frameworks: ['jasmine', '@angular-devkit/build-angular'],
    plugins: [
      require('karma-jasmine'),
      require('karma-chrome-launcher'),
      require('karma-jasmine-html-reporter'),
      require('karma-coverage'),
      require('@angular-devkit/build-angular/plugins/karma'),
    ],
    client: {
      jasmine: {
        // Surface ordering dependencies between specs rather than hiding them.
        random: true,
        failSpecWithNoExpectations: true,
      },
      clearContext: false,
    },
    jasmineHtmlReporter: { suppressAll: true },
    coverageReporter: {
      dir: require('path').join(__dirname, './coverage'),
      subdir: '.',
      reporters: [{ type: 'text-summary' }, { type: 'lcovonly' }],
    },
    reporters: ['progress', 'kjhtml'],
    browsers: ['ChromeHeadlessNoSandbox'],
    customLaunchers: {
      ChromeHeadlessNoSandbox: {
        base: 'ChromeHeadless',
        // --no-sandbox is required in containers; the rest keep a headless
        // Chrome from stalling on a machine with no GPU or /dev/shm.
        flags: [
          '--no-sandbox',
          '--disable-gpu',
          '--disable-dev-shm-usage',
          '--headless=new',
          '--remote-debugging-port=9222',
        ],
      },
    },
    restartOnFileChange: true,
    singleRun: false,
    captureTimeout: 120000,
    browserDisconnectTimeout: 30000,
    browserNoActivityTimeout: 60000,
  });
};
