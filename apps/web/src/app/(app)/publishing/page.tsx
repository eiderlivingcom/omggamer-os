import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Publishing" };

export default function Page() {
  return <ModulePage href="/publishing" description="Approval and publishing queues for omggamer.com, plus anything that failed to sync." />;
}
