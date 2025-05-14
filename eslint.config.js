import js from "@eslint/js";
import globals from "globals";
import tseslint from "typescript-eslint";
import pluginVue from "eslint-plugin-vue";
import { defineConfig } from "eslint/config";

export default defineConfig([
  { 
    files: ["**/*.{js,mjs,cjs,ts,vue}"], 
    plugins: { js }, 
    extends: ["js/recommended"] 
  },
  { 
    files: ["**/*.{js,mjs,cjs,ts,vue}"], 
    languageOptions: { 
      globals: {...globals.browser, ...globals.node} 
    } 
  },
  tseslint.configs.recommended,
  pluginVue.configs["flat/essential"],
  { 
    files: ["**/*.vue"], 
    languageOptions: { 
      parserOptions: { 
        parser: tseslint.parser 
      } 
    } 
  },
   {
    // ✅ Bloco customizado com regras específicas
    rules: {
      "vue/multi-word-component-names": "off",
      "@typescript-eslint/no-explicit-any": "off",
      "@typescript-eslint/no-unused-vars": ["warn", { argsIgnorePattern: "^_" }],
    },
  },
]);
