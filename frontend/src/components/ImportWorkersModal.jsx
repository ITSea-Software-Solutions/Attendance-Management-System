import { useRef, useState } from "react";
import * as XLSX from "xlsx";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import {
  X, UploadCloud, FileSpreadsheet, Download, CheckCircle2, AlertTriangle,
  ArrowRight, Users,
} from "lucide-react";

/**
 * Excel-first import wizard: drag in the .xlsx/.csv people already have,
 * see exactly how their columns were understood BEFORE anything uploads,
 * then get a full per-row report. The server keeps doing the real
 * validation — this mirrors its header aliases only to reassure upfront.
 */

const FIELDS = {
  name:         { label: "Name *",          aliases: ["name", "workername", "fullname"] },
  emp_code:     { label: "Emp code",        aliases: ["empcode", "employeecode", "empno", "employeeno", "empid", "staffcode", "code"] },
  phone:        { label: "Phone",           aliases: ["phone", "mobile", "phoneno", "mobileno", "contact"] },
  joining_date: { label: "Joining date",    aliases: ["joiningdate", "doj", "dateofjoining", "joindate"] },
  aadhaar:      { label: "Aadhaar (optional)", aliases: ["aadhaarnumber", "aadhaarno", "aadhaar", "adharno", "adhar", "adhaar", "aadharnumber", "aadharno", "aadhar", "uid"] },
  pan:          { label: "PAN",             aliases: ["pannumber", "panno", "pan", "pancard"] },
  address:      { label: "Address",         aliases: ["address", "addr", "fulladdress"] },
  dob:          { label: "Date of birth",   aliases: ["dob", "dateofbirth", "birthdate"] },
  gender:       { label: "Gender",          aliases: ["gender", "sex"] },
  email:        { label: "Email",           aliases: ["email", "emailid", "mail"] },
};

const norm = (h) => String(h ?? "").toLowerCase().replace(/[^a-z0-9]/g, "");

function mapHeader(h) {
  const n = norm(h);
  for (const [field, def] of Object.entries(FIELDS)) {
    if (def.aliases.includes(n)) return field;
  }
  return null;
}

function toCsv(rows) {
  const esc = (v) => {
    const s = String(v ?? "");
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  return rows.map((r) => r.map(esc).join(",")).join("\n");
}

export function downloadTemplate() {
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet([
    ["Name", "Emp Code", "Phone", "Joining Date", "Aadhaar Number", "PAN", "Address"],
    ["Ramesh Kumar", "E001", "9876543210", "01/04/2024", "", "ABCDE1234F", "Karvenagar, Pune"],
    ["Sunita Devi", "E002", "9876543211", "15/06/2024", "483211112222", "", "Hadapsar, Pune"],
  ]);
  ws["!cols"] = [{ wch: 18 }, { wch: 10 }, { wch: 13 }, { wch: 13 }, { wch: 16 }, { wch: 12 }, { wch: 26 }];
  XLSX.utils.book_append_sheet(wb, ws, "Workers");
  XLSX.writeFile(wb, "truecrew-worker-import-template.xlsx");
}

export default function ImportWorkersModal({ open, onClose, onImported, vendorOpts, isVendorUser, defaultVendorId }) {
  const fileRef = useRef(null);
  const [drag, setDrag] = useState(false);
  const [parsed, setParsed] = useState(null);   // {fileName, header, rows, mapping}
  const [vendorId, setVendorId] = useState(defaultVendorId || "");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);   // server response

  if (!open) return null;

  const reset = () => { setParsed(null); setResult(null); setBusy(false); };

  const handleFile = async (file) => {
    if (!file) return;
    try {
      const wb = XLSX.read(await file.arrayBuffer(), { raw: false });
      const ws = wb.Sheets[wb.SheetNames[0]];
      const aoa = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: "" });
      const header = (aoa[0] ?? []).map((h) => String(h ?? "").trim());
      const rows = aoa.slice(1).filter((r) => r.some((c) => String(c ?? "").trim() !== ""));
      if (!header.length || !rows.length) {
        toast.error("That sheet looks empty — the first row must be the column headings.");
        return;
      }
      const mapping = header.map((h) => ({ header: h, field: mapHeader(h) }));
      if (!mapping.some((m) => m.field === "name")) {
        toast.error('No "Name" column found — add a Name heading (see the template).');
        return;
      }
      setResult(null);
      setParsed({ fileName: file.name, header, rows, mapping });
    } catch {
      toast.error("Could not read that file — save it as .xlsx or .csv and try again.");
    }
  };

  const runImport = async () => {
    if (!isVendorUser && !vendorId) {
      toast.error("Choose which vendor these workers belong to.");
      return;
    }
    setBusy(true);
    try {
      const csv = toCsv([parsed.header, ...parsed.rows]);
      const fd = new FormData();
      fd.append("file", new Blob(["﻿" + csv], { type: "text/csv" }), "import.csv");
      if (!isVendorUser) fd.append("vendor_id", vendorId);
      const r = await api.post("/workers-import", fd, { headers: { "Content-Type": "multipart/form-data" } });
      setResult(r.data);
      onImported?.();
    } catch (err) {
      toast.error(err.response?.data?.message
        ?? (err.response?.status === 403
          ? "Bulk import is a Professional/Enterprise feature — see Plan & Billing."
          : "Import failed — check the file and try again."));
    } finally {
      setBusy(false);
    }
  };

  const recognized = parsed?.mapping.filter((m) => m.field) ?? [];
  const ignored = parsed?.mapping.filter((m) => !m.field && m.header) ?? [];

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 sticky top-0 bg-white z-10">
          <h2 className="font-semibold text-gray-900 flex items-center gap-2">
            <FileSpreadsheet size={18} className="text-brand-600" /> Import workers from Excel
          </h2>
          <button onClick={() => { reset(); onClose(); }}><X size={18} className="text-gray-400" /></button>
        </div>

        <div className="p-6 space-y-4">
          {/* ── Results view ─────────────────────────────────────────── */}
          {result ? (
            <div className="space-y-4">
              <div className="grid grid-cols-3 gap-3 text-center">
                <div className="rounded-xl bg-emerald-50 border border-emerald-200 py-4">
                  <p className="text-3xl font-bold text-emerald-700">{result.created}</p>
                  <p className="text-xs text-emerald-800 font-medium">imported</p>
                </div>
                <div className="rounded-xl bg-amber-50 border border-amber-200 py-4">
                  <p className="text-3xl font-bold text-amber-700">{result.without_aadhaar ?? 0}</p>
                  <p className="text-xs text-amber-800 font-medium">Aadhaar pending<br />(verify later)</p>
                </div>
                <div className="rounded-xl bg-red-50 border border-red-200 py-4">
                  <p className="text-3xl font-bold text-red-600">{result.errors?.length ?? 0}</p>
                  <p className="text-xs text-red-700 font-medium">rows skipped</p>
                </div>
              </div>
              {!!result.errors?.length && (
                <div className="border border-red-100 rounded-xl overflow-hidden">
                  <p className="bg-red-50 px-4 py-2 text-xs font-semibold text-red-700">
                    Skipped rows — fix these in your Excel and import just those rows again:
                  </p>
                  <ul className="max-h-44 overflow-y-auto divide-y divide-red-50 text-sm">
                    {result.errors.map((e, i) => (
                      <li key={i} className="px-4 py-1.5 text-red-700 flex gap-2">
                        <AlertTriangle size={13} className="shrink-0 mt-0.5" /> {e}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              <div className="flex justify-between">
                <button className="btn-secondary" onClick={reset}>Import another file</button>
                <button className="btn-primary" onClick={() => { reset(); onClose(); }}>Done</button>
              </div>
            </div>
          ) : !parsed ? (
            /* ── Step 1: pick/drag file ─────────────────────────────── */
            <>
              <button
                type="button"
                className={`w-full rounded-2xl border-2 border-dashed px-6 py-12 text-center transition-colors ${
                  drag ? "border-brand-500 bg-brand-50" : "border-gray-300 hover:border-brand-400"}`}
                onClick={() => fileRef.current?.click()}
                onDragOver={(e) => { e.preventDefault(); setDrag(true); }}
                onDragLeave={() => setDrag(false)}
                onDrop={(e) => { e.preventDefault(); setDrag(false); handleFile(e.dataTransfer.files?.[0]); }}
              >
                <UploadCloud size={40} className="mx-auto text-brand-500 mb-3" />
                <p className="font-semibold text-gray-800">Drop your Excel file here, or click to choose</p>
                <p className="text-sm text-gray-500 mt-1">.xlsx, .xls or .csv — your existing sheet works as-is</p>
                <input ref={fileRef} type="file" accept=".xlsx,.xls,.csv" className="hidden"
                  onChange={(e) => { handleFile(e.target.files?.[0]); e.target.value = ""; }} />
              </button>
              <div className="flex items-center justify-between rounded-xl bg-gray-50 border border-gray-100 px-4 py-3">
                <div className="text-sm text-gray-600">
                  <p className="font-medium text-gray-800">Starting fresh?</p>
                  <p className="text-xs">Download the ready-made Excel template — fill it, then drop it above.</p>
                </div>
                <button className="btn-secondary text-sm" onClick={downloadTemplate}>
                  <Download size={14} /> Template
                </button>
              </div>
              <p className="text-[11px] text-gray-400">
                Only <b>Name</b> is required. Aadhaar can be left empty — those workers import as
                "Aadhaar pending" and get verified later. Dates work as dd/mm/yyyy.
              </p>
            </>
          ) : (
            /* ── Step 2: preview + confirm ──────────────────────────── */
            <>
              <div className="flex items-center justify-between">
                <p className="text-sm text-gray-700">
                  <FileSpreadsheet size={14} className="inline mr-1 text-brand-600" />
                  <b>{parsed.fileName}</b> — {parsed.rows.length} worker row{parsed.rows.length === 1 ? "" : "s"} found
                </p>
                <button className="text-xs text-gray-400 underline" onClick={reset}>choose a different file</button>
              </div>

              {/* Column understanding */}
              <div className="rounded-xl border border-gray-100 overflow-hidden">
                <p className="bg-gray-50 px-4 py-2 text-xs font-semibold text-gray-600">How your columns were understood</p>
                <div className="px-4 py-2 flex flex-wrap gap-1.5">
                  {recognized.map((m) => (
                    <span key={m.header} className="inline-flex items-center gap-1 text-[11px] bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-full px-2.5 py-1">
                      <CheckCircle2 size={11} /> {m.header} <ArrowRight size={9} /> {FIELDS[m.field].label}
                    </span>
                  ))}
                  {ignored.map((m) => (
                    <span key={m.header} className="inline-flex items-center gap-1 text-[11px] bg-gray-50 text-gray-400 border border-gray-200 rounded-full px-2.5 py-1"
                      title="This column will be ignored">
                      {m.header} — ignored
                    </span>
                  ))}
                </div>
              </div>

              {/* First rows preview */}
              <div className="rounded-xl border border-gray-100 overflow-x-auto">
                <table className="w-full text-xs">
                  <thead className="bg-gray-50 text-gray-500">
                    <tr>{parsed.header.map((h, i) => <th key={i} className="px-3 py-1.5 text-left font-semibold whitespace-nowrap">{h}</th>)}</tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {parsed.rows.slice(0, 5).map((r, i) => (
                      <tr key={i}>{parsed.header.map((_, j) => <td key={j} className="px-3 py-1.5 whitespace-nowrap text-gray-700">{String(r[j] ?? "")}</td>)}</tr>
                    ))}
                  </tbody>
                </table>
                {parsed.rows.length > 5 && (
                  <p className="px-3 py-1.5 text-[11px] text-gray-400 bg-gray-50">…and {parsed.rows.length - 5} more rows</p>
                )}
              </div>

              {/* Vendor picker for super admin */}
              {!isVendorUser && (
                <div className="flex items-center gap-3">
                  <Users size={15} className="text-gray-400" />
                  <select className="input w-auto text-sm" value={vendorId} onChange={(e) => setVendorId(e.target.value)}>
                    <option value="">These workers belong to which vendor?</option>
                    {(vendorOpts ?? []).map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                  </select>
                </div>
              )}

              <div className="flex justify-end gap-2 pt-1">
                <button className="btn-secondary" onClick={() => { reset(); onClose(); }}>Cancel</button>
                <button className="btn-primary" disabled={busy} onClick={runImport}>
                  {busy ? "Importing…" : `Import ${parsed.rows.length} worker${parsed.rows.length === 1 ? "" : "s"}`}
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
