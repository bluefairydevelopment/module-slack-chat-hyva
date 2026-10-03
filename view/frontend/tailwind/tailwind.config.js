/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * Read by Hyvä themes still on the Tailwind 3 toolchain, which merge each
 * registered module's tailwind.config.js into the theme's own (the pattern
 * BlueFairy_PackagistProvisionerHyva ships). Tailwind 4 themes use module.css
 * and its @source instead. Only content paths are declared: the templates use
 * Tailwind's stock palette, so no colours are added.
 *
 * Same glob as module.css; TailwindScansThisPackagesTemplatesTest checks both.
 */
module.exports = {
    content: [
        '../templates/**/*.phtml',
    ],
};
