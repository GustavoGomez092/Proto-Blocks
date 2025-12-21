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
const OUTPUT_FILE = path.join(DIST_DIR, `${PLUGIN_SLUG}.zip`);

// Files and directories to include
const INCLUDE_PATTERNS = [
    'proto-blocks.php',
    'README.md',
    'includes/**/*',
    'assets/js/*.js',
    'assets/js/*.php',
    'assets/css/**/*',
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
            console.log(`\nCreated: dist/${PLUGIN_SLUG}.zip (${sizeKB} KB)`);
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
