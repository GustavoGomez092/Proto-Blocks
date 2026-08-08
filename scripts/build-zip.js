/**
 * Build Distribution Zip
 *
 * Creates a production-ready zip file with only the necessary files.
 */

const fs = require('fs');
const path = require('path');
const archiver = require('archiver');

const PLUGIN_SLUG = 'proto-blocks';
const ROOT_DIR = path.resolve(__dirname, '..');
const DIST_DIR = path.join(ROOT_DIR, 'dist');
// Stamp the plugin version into the zip name, e.g. proto-blocks-2.0.1.zip.
const VERSION = require(path.join(ROOT_DIR, 'package.json')).version;
const ZIP_NAME = `${PLUGIN_SLUG}-${VERSION}.zip`;
const OUTPUT_FILE = path.join(DIST_DIR, ZIP_NAME);

// Files and directories to include.
//
// `assets/**/*` rather than a per-directory list: the previous allow-list named
// assets/js and assets/css only, so when Preview Capture added assets/admin and
// assets/vendor those were silently left out of every zip and the feature 404'd
// on install while working fine from a git checkout. Everything under assets/ is
// build output or vendored runtime, so shipping all of it is correct — and it
// cannot go stale the next time a directory is added. verifyReferencedAssets()
// below is the backstop.
const INCLUDE_PATTERNS = [
    'proto-blocks.php',
    'README.md',
    'includes/**/*',
    'assets/**/*',
    'examples/**/*',
];

// Files to explicitly exclude
const EXCLUDE_PATTERNS = [
    '*.map',
    '.DS_Store',
    'Thumbs.db',
];

/**
 * Get all files matching the include patterns
 */
function getFilesToInclude() {
    const files = [];

    INCLUDE_PATTERNS.forEach(pattern => {
        if (pattern.includes('**')) {
            // Handle glob patterns
            const basePath = pattern.split('**')[0];
            const fullBasePath = path.join(ROOT_DIR, basePath);

            if (fs.existsSync(fullBasePath)) {
                walkDirectory(fullBasePath, (filePath) => {
                    const relativePath = path.relative(ROOT_DIR, filePath);
                    if (!shouldExclude(relativePath)) {
                        files.push(relativePath);
                    }
                });
            }
        } else if (pattern.includes('*')) {
            // Handle simple wildcards
            const dir = path.dirname(pattern);
            const ext = path.extname(pattern);
            const fullDir = path.join(ROOT_DIR, dir);

            if (fs.existsSync(fullDir)) {
                fs.readdirSync(fullDir).forEach(file => {
                    if (ext === '' || file.endsWith(ext)) {
                        const relativePath = path.join(dir, file);
                        if (!shouldExclude(relativePath)) {
                            files.push(relativePath);
                        }
                    }
                });
            }
        } else {
            // Handle exact file paths
            const fullPath = path.join(ROOT_DIR, pattern);
            if (fs.existsSync(fullPath) && !shouldExclude(pattern)) {
                files.push(pattern);
            }
        }
    });

    return [...new Set(files)]; // Remove duplicates
}

/**
 * Walk directory recursively
 */
function walkDirectory(dir, callback) {
    if (!fs.existsSync(dir)) return;

    const entries = fs.readdirSync(dir, { withFileTypes: true });

    entries.forEach(entry => {
        const fullPath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            walkDirectory(fullPath, callback);
        } else if (entry.isFile()) {
            callback(fullPath);
        }
    });
}

/**
 * Check if a file should be excluded
 */
function shouldExclude(filePath) {
    const fileName = path.basename(filePath);

    return EXCLUDE_PATTERNS.some(pattern => {
        if (pattern.startsWith('*.')) {
            return fileName.endsWith(pattern.slice(1));
        }
        return fileName === pattern;
    });
}

/**
 * Fail the build if the PHP references an asset the zip does not carry.
 *
 * The include list is an allow-list, so anything not named in it is dropped
 * silently — a zip builds cleanly, uploads, installs, and only then 404s in the
 * browser. That is exactly how assets/admin and assets/vendor went missing.
 *
 * Rather than hard-coding a manifest that would drift in the same way, this
 * reads the truth out of the source: every 'assets/…' literal the PHP enqueues
 * must exist on disk AND be in the file list. Add a new asset directory and the
 * check picks it up with no edit here.
 *
 * Only literal paths can be checked; a path built from variables is invisible
 * to a regex. That is a best-effort floor, not a ceiling.
 */
function verifyReferencedAssets(files) {
    const shipped = new Set(files.map(f => f.split(path.sep).join('/')));
    const sources = [];

    walkDirectory(path.join(ROOT_DIR, 'includes'), p => {
        if (p.endsWith('.php')) sources.push(p);
    });
    sources.push(path.join(ROOT_DIR, 'proto-blocks.php'));

    const referenced = new Set();
    sources.forEach(file => {
        if (!fs.existsSync(file)) return;
        const text = fs.readFileSync(file, 'utf8');
        const re = /assets\/[A-Za-z0-9_./-]+\.(?:js|css|php|wasm)/g;
        let m;
        while ((m = re.exec(text)) !== null) referenced.add(m[0]);
    });

    const missing = [...referenced]
        .filter(rel => fs.existsSync(path.join(ROOT_DIR, rel)))  // on disk…
        .filter(rel => !shipped.has(rel))                        // …but not packaged
        .sort();

    if (missing.length) {
        console.error('\nThese assets are referenced by the PHP but would not ship:\n');
        missing.forEach(f => console.error(`  - ${f}`));
        console.error('\nAdd them to INCLUDE_PATTERNS in scripts/build-zip.js.\n');
        process.exit(1);
    }

    console.log(`Verified ${referenced.size} referenced asset path(s) are packaged.`);
}

/**
 * Build the zip file
 */
async function buildZip() {
    console.log('Building distribution zip...\n');

    // Ensure dist directory exists
    if (!fs.existsSync(DIST_DIR)) {
        fs.mkdirSync(DIST_DIR, { recursive: true });
    }

    // Remove existing zip if present
    if (fs.existsSync(OUTPUT_FILE)) {
        fs.unlinkSync(OUTPUT_FILE);
    }

    // Get files to include
    const files = getFilesToInclude();

    if (files.length === 0) {
        console.error('No files found to include in zip!');
        process.exit(1);
    }

    // Before anything is written: would this zip actually run once installed?
    verifyReferencedAssets(files);

    console.log(`Including ${files.length} files:\n`);

    // Group files by directory for display
    const byDir = {};
    files.forEach(file => {
        const dir = path.dirname(file) || '.';
        if (!byDir[dir]) byDir[dir] = [];
        byDir[dir].push(path.basename(file));
    });

    Object.keys(byDir).sort().forEach(dir => {
        console.log(`  ${dir}/`);
        byDir[dir].forEach(file => {
            console.log(`    - ${file}`);
        });
    });

    console.log('');

    // Create zip archive
    const output = fs.createWriteStream(OUTPUT_FILE);
    const archive = archiver('zip', {
        zlib: { level: 9 } // Maximum compression
    });

    return new Promise((resolve, reject) => {
        output.on('close', () => {
            const sizeKB = (archive.pointer() / 1024).toFixed(2);
            console.log(`\nCreated: dist/${ZIP_NAME} (${sizeKB} KB)`);
            resolve();
        });

        archive.on('error', (err) => {
            reject(err);
        });

        archive.pipe(output);

        // Add each file to the archive under the plugin slug directory
        files.forEach(file => {
            const sourcePath = path.join(ROOT_DIR, file);
            const archivePath = path.join(PLUGIN_SLUG, file);
            archive.file(sourcePath, { name: archivePath });
        });

        archive.finalize();
    });
}

// Run
buildZip().catch(err => {
    console.error('Error building zip:', err);
    process.exit(1);
});
