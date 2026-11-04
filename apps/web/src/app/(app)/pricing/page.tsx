import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Pricing" };

export default function Page() {
  return <ModulePage href="/pricing" description="Margin rules by supplier, platform or category, rounding, currencies and the price history of every variant." />;
}
