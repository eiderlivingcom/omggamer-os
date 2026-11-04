"use client";

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { api, type Me } from "./api";

type Session = { me: Me | null; loading: boolean; can: (perm: string) => boolean; reload: () => Promise<void> };

const Ctx = createContext<Session>({ me: null, loading: true, can: () => false, reload: async () => {} });

export function SessionProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [loading, setLoading] = useState(true);

  const reload = useCallback(async () => {
    try {
      setMe(await api<Me>("/auth/me"));
    } catch {
      setMe(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void reload();
  }, [reload]);

  const can = useCallback((perm: string) => !!me?.permissions.includes(perm), [me]);
  return <Ctx.Provider value={{ me, loading, can, reload }}>{children}</Ctx.Provider>;
}

export const useSession = () => useContext(Ctx);
