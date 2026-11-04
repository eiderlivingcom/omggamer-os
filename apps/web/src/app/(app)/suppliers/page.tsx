import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Suppliers" };

export default function Page() {
  return <ModulePage href="/suppliers" description="Supplier connections and imports. Kinguin is the first supplier; others plug into the same import pipeline." />;
}
