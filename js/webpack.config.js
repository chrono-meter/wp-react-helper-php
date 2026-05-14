import * as path from 'path';
import * as fs from 'fs';
import { createRequire } from 'module';
const require = createRequire(import.meta.url);


// https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/#:~:text=Extending%20the%20webpack%20config
import defaultConfig from '@wordpress/scripts/config/webpack.config.js';
// console.dir(defaultConfig.module.rules, { depth: null });
const packageJson = JSON.parse(fs.readFileSync('package.json', 'utf8'));


import DependencyExtractionWebpackPlugin from '@wordpress/dependency-extraction-webpack-plugin';
// import CopyWebpackPlugin from 'copy-webpack-plugin';


// https://github.com/WordPress/gutenberg/tree/trunk/packages/dependency-extraction-webpack-plugin
// https://gist.github.com/chrono-meter/e92920515b010d01bd5d32d2facf21d0
function addNotBundledModuleMarkerPlugin(notWpCoreBundledModules, ...plugins) {
    function requestToExternal(request) {
        if (notWpCoreBundledModules.includes(request)) {
            return false;
        }
        // With `new DependencyExtractionWebpackPlugin({ useDefaults: true, ... }`, `return;` means default behavior.
    }

    return plugins?.map(plugin => {
        if (plugin?.constructor?.name === 'DependencyExtractionWebpackPlugin') {
            console.warn(`Replacing DependencyExtractionWebpackPlugin for not bundled modules: ${notWpCoreBundledModules.join(', ')}`);

            return new DependencyExtractionWebpackPlugin({
                requestToExternal,
                requestToExternalModule: requestToExternal,
            });
        } else {
            return plugin;
        }
    });
}


defaultConfig.plugins = addNotBundledModuleMarkerPlugin([
    '@wordpress/react-i18n',
    ...(packageJson?.extra?.externalModules || []),
], ...defaultConfig.plugins);


/**
 * babel-plugin-styled-components
 */
try {
    require('babel-plugin-styled-components');  // Ensure babel-plugin-styled-components is installed
    defaultConfig.plugins = [
        ...defaultConfig.plugins,
        'babel-plugin-styled-components',
    ];
} catch (e) {
    console.warn('`babel-plugin-styled-components` is not installed. Skipping styled-components setup.');
}


/**
 * Add custom resolve modules for webpack. This allows us to import modules from the `packages` directory without having to specify the relative path.
 * todo: Obsolete this feature.
 */
defaultConfig.resolve = {
    ...defaultConfig.resolve,
    // https://webpack.js.org/configuration/resolve/#resolvemodules
    modules: [
        ...(packageJson?.extra?.webpackResolvePathes || []),
        path.resolve(process.cwd(), 'packages'),
        path.resolve(process.cwd(), 'node_modules'),
        ...(defaultConfig.resolve.modules || []),
    ].filter(fs.existsSync),
};


/**
 * Custom way for build as module. `wp-scripts` provieded defaults are not suitable for our needs.
 */
// todo More config-less and stable way to get the entry points. Detect by file name like `*.module.js`?
const blockEntries = defaultConfig.entry();
const moduleEntries = {};

for (const key of Object.keys(blockEntries)) {
    if (key.endsWith('Block/view')) {
        moduleEntries[key] = blockEntries[key];
        delete blockEntries[key];
        console.warn(`${key} is moved to module entries.`);
    }
}


/**
 * Rewrite `block.json` for our needs.
 *
 * @link https://blockifywp.com/how-to-use-typescript-and-tsx-for-wordpress-block-development/
 * @link https://github.com/WordPress/gutenberg/blob/trunk/packages/scripts/config/webpack.config.js
 */
defaultConfig.plugins.forEach(plugin => {
    if (plugin?.constructor?.name === 'CopyWebpackPlugin') {
        // if (plugin instanceof CopyWebpackPlugin) {
        plugin.patterns = plugin.patterns.map(pattern => {
            if (pattern.from === '**/block.json') {
                const transform = pattern.transform;
                pattern.transform = function (content, absoluteFrom) {
                    const result = transform(content, absoluteFrom);

                    if (path.basename(absoluteFrom) === 'block.json') {
                        const blockJson = JSON.parse(content.toString());

                        if (blockJson.viewScript && blockJson.viewScriptModule) {
                            throw new Error(`block.json at ${filePath} has both viewScript and viewScriptModule. Please remove one of them.`);
                        }

                        blockJson.editorScript = blockJson.editorScript?.replace(/\.tsx$/, '.js');
                        blockJson.script = blockJson.script?.replace(/\.tsx$/, '.js');
                        // blockJson.viewScript = blockJson.viewScript?.replace(/\.tsx$/, '.js');
                        // blockJson.viewScriptModule = blockJson.viewScriptModule?.replace(/\.tsx$/, '.js');
                        if (blockJson.viewScript) {
                            blockJson.viewScriptModule = blockJson.viewScript?.replace(/\.tsx$/, '.js');
                            delete blockJson.viewScript;
                        }
                        blockJson.editorStyle = blockJson.editorStyle?.replace(/\.scss$/, '.css');
                        blockJson.style = blockJson.style?.replace(/\.scss$/, '.css');

                        return JSON.stringify(blockJson, null, 2);
                    }

                    return result;
                };
            }

            return pattern;
        });
    }
});


/**
 * React compiler
 *
 * @link https://react.dev/learn/react-compiler
 * @link https://github.com/SukkaW/react-compiler-webpack
 */
try {
    const { defineReactCompilerLoaderOption, reactCompilerLoader } = require('react-compiler-webpack');
    require('react-compiler-runtime');  // Ensure react-compiler-runtime is installed, it is required for React 17, 18.

    defaultConfig.module.rules.unshift({
        test: /\.m?(j|t)sx?$/,
        exclude: /node_modules/,
        use: [
            // babel-loader, swc-loader, esbuild-loader, or anything you like to transpile JSX should go here.
            // If you are using rspack, the rspack's built-in react transformation is sufficient.
            // {
            //     loader: 'babel-loader',
            //     options: { cacheDirectory: true },
            // },
            // Now add reactCompilerLoader
            {
                loader: reactCompilerLoader,
                options: defineReactCompilerLoaderOption({
                    // React Compiler options goes here
                    target: '18',
                }),
            },
        ],
    });
} catch (e) {
    console.warn('react-compiler-webpack is not installed. Skipping react compiler setup.');
}


// todo: webpack-bundle-analyzer makes broken json file.
// /**
//  * Bundle analyzer
//  *
//  * @link https://www.npmjs.com/package/webpack-bundle-analyzer
//  */
// try {
//     const BundleAnalyzerPlugin = require('webpack-bundle-analyzer').BundleAnalyzerPlugin;
//     defaultConfig.plugins = [
//         new BundleAnalyzerPlugin({
//             analyzerMode: 'disabled',
//             generateStatsFile: true,
//             statsFilename: 'stats.json',
//             // statsOptions: { source: false },
//             // logLevel: 'warn',
//         }),
//         ...(defaultConfig.plugins || []),
//     ];
//     defaultConfig.stats = 'none';
// } catch (e) {
//     console.warn('webpack-bundle-analyzer is not installed. Skipping bundle analyzer setup.', e, defaultConfig);
//     throw e;
// }


// https://webpack.js.org/configuration/configuration-types/#exporting-multiple-configurations
export default [
    {
        ...defaultConfig,
        name: 'webpack-config-cjs',
        entry: blockEntries,
    },
    {
        ...defaultConfig,
        name: 'webpack-config-esm',
        target: 'web',
        entry: {
            ...(packageJson?.extra?.moduleEntries || {}),
            ...moduleEntries,
        },
        // https://stackoverflow.com/a/75142079
        experiments: {
            ...defaultConfig.experiments,
            outputModule: true,
        },
        output: {
            ...defaultConfig.output,
            // libraryTarget: 'module',
            library: {
                type: 'module',
            },
            /**
             * Change chunk file name to prevent conflicts with other configurations.
             */
            chunkFilename: '[id].esm.js',  // https://webpack.js.org/configuration/output/#outputchunkfilename
        },
    },
];
