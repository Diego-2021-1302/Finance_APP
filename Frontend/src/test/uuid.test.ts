import { describe, expect, it, vi } from 'vitest';
import { createUuid } from '../api/uuid';

describe('createUuid', () => {
  it('creates a UUID v4 using getRandomValues without relying on randomUUID', () => {
    const cryptoMock = {
      getRandomValues: vi.fn((bytes: Uint8Array) => {
        bytes.fill(0);
        return bytes;
      }),
    };
    vi.stubGlobal('crypto', cryptoMock);

    expect(createUuid()).toBe('00000000-0000-4000-8000-000000000000');
    expect(cryptoMock.getRandomValues).toHaveBeenCalledOnce();
  });
});
