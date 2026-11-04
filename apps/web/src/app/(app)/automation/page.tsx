import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Automation" };

export default function Page() {
  return <ModulePage href="/automation" description="Scheduled imports, price and stock synchronisation, and scheduled AI processing." />;
}
