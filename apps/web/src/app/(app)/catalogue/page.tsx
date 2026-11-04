import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Catalogue" };

export default function Page() {
  return <ModulePage href="/catalogue" description="Master products, variants and supplier offers. Supplier data, AI text and manual edits are stored separately, so nothing original is ever overwritten." />;
}
