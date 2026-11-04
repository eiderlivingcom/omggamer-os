import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Categories" };

export default function Page() {
  return <ModulePage href="/categories" description="The category tree, attributes and tags, and how each supplier's categories map onto them." />;
}
