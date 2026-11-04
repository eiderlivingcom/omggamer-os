import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Analytics" };

export default function Page() {
  return <ModulePage href="/analytics" description="Catalogue, supplier, AI cost and sales reports." />;
}
