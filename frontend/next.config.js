/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // This will enable i18n routing features in Next.js
  i18n: {
    locales: ['en', 'zh_TW', 'zh_CN'],
    defaultLocale: 'zh_TW',
    // localeDetection: false, // You might want to disable this for explicit tenant routing
  },
  // Add rewrites to handle tenant subdomains
  async rewrites() {
    return [
      // Rewrite requests from the root domain to the tenant proxy,
      // assuming a structure like app.yourdomain.com for the main app
      // and cw.yourdomain.com for specific tenants.
      // For local development, this might route from localhost:3000
      // to an internal dynamic path that extracts tenant info.
      {
        source: '/:path*',
        destination: '/_tenant_proxy/:path*', // Internal proxy path
      },
    ];
  },
};

module.exports = nextConfig;
