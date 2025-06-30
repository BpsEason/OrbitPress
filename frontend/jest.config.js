const nextJest = require('next/jest');

const createJestConfig = nextJest({
  // 提供您的 Next.js 應用程式路徑，以在測試環境中加載 next.config.js 和 .env 文件
  dir: './',
});

// 添加任何要傳遞給 Jest 的自定義配置
const customJestConfig = {
  setupFilesAfterEnv: ['<rootDir>/jest.setup.js'],
  moduleNameMapper: {
    // 處理模塊別名 (如果在 jsconfig.json 或 tsconfig.json 中配置)
    '^@/components/(.*)$': '<rootDir>/components/$1',
    '^@/pages/(.*)$': '<rootDir>/pages/$1',
    // ... 其他別名
  },
  testEnvironment: 'jest-environment-jsdom',
};

// createJestConfig 以這種方式導出，以確保 next/jest 可以加載異步的 Next.js 配置
module.exports = createJestConfig(customJestConfig);
