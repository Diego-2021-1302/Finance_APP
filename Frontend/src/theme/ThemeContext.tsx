import { createContext, useContext } from 'react';

export interface ThemeContextValue {
  isDark: boolean;
  setAppearance: (appearance: 'light' | 'dark' | 'system') => void;
}

export const ThemeContext = createContext<ThemeContextValue>({
  isDark: false,
  setAppearance: () => undefined,
});

export function useAppTheme(): ThemeContextValue {
  return useContext(ThemeContext);
}
