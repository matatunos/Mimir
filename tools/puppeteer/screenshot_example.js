// Example puppeteer script to login and capture screenshots
// Usage:
// BASE_URL=https://doc.favala.es USERNAME=myuser PASSWORD=mysecret node tools/puppeteer/screenshot_example.js

const puppeteer = require('puppeteer');

(async () => {
  const baseUrl = process.env.BASE_URL || 'https://doc.favala.es';
  const username = process.env.USERNAME;
  const password = process.env.PASSWORD;

  if (!username || !password) {
    console.error('Please set USERNAME and PASSWORD environment variables.');
    process.exit(2);
  }

  const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  const page = await browser.newPage();
  page.setViewport({ width: 1366, height: 768 });

  try {
    // Login page
    await page.goto(baseUrl + '/login.php', { waitUntil: 'networkidle2' });
    await page.screenshot({ path: 'docs/screenshots/01-login.png', fullPage: true });

    // Fill login form - selectors may need adjustment per deployment
    await page.type('input[name=username], input[name=email], input[id=username]', username, { delay: 50 }).catch(()=>{});
    await page.type('input[name=password], input[id=password]', password, { delay: 50 }).catch(()=>{});
    await Promise.all([
      page.click('button[type=submit], button.login-button'),
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }).catch(()=>{})
    ]);

    // Capture My Files (dashboard) after login
    if (typeof page.waitForTimeout === 'function') {
      await page.waitForTimeout(800);
    } else {
      await new Promise(r => setTimeout(r, 800));
    }
    await page.screenshot({ path: 'docs/screenshots/03-my-files.png', fullPage: true });

    // --- Upload a test file ---
    try {
      await page.goto(baseUrl + '/user/upload.php', { waitUntil: 'networkidle2' });
      await page.screenshot({ path: 'docs/screenshots/04-upload-select.png', fullPage: true });
      const input = await page.$('input[type=file][name="files[]"]');
      if (input) {
        await input.uploadFile('tools/puppeteer/test_assets/sample.png');
        // Submit the form
        await Promise.all([
          page.click('button[type=submit]'),
          page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }).catch(()=>{})
        ]);
        await page.screenshot({ path: 'docs/screenshots/05-upload-result.png', fullPage: true });
      }
    } catch (err) {
      console.error('Upload step failed:', err.message);
    }

    // --- Publish first file to gallery (click publish button on files list) ---
    try {
      await page.goto(baseUrl + '/user/files.php', { waitUntil: 'networkidle2' });
      // Wait for publish button and click the first one
      await page.waitForSelector('.publish-gallery-btn', { timeout: 8000 });
      await page.click('.publish-gallery-btn');
      // Wait for modal to appear
      await page.waitForSelector('#publishGalleryModal', { visible: true, timeout: 8000 });
      await page.screenshot({ path: 'docs/screenshots/09-gallery-modal.png', fullPage: true });
    } catch (err) {
      console.error('Publish step failed:', err.message);
    }

    // Additional manual steps can be scripted here, for example:
    // - Open upload dialog (click selector), capture
    // - Click on an image thumbnail to open preview, capture

    console.log('Screenshots saved to docs/screenshots/');
  } catch (err) {
    console.error('Error during screenshot process:', err.message);
  } finally {
    await browser.close();
  }
})();
