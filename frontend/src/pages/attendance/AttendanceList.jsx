import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import api from "@/lib/axios";
import AuthImg from "@/components/AuthImg";
import PageHint from "@/components/PageHint";
import { useAuth } from "@/contexts/AuthContext";
import MultiSelect from "@/components/MultiSelect";
import { Download, Printer, X, User, CalendarRange, Calendar } from "lucide-react";
import { format, differenceInMinutes } from "date-fns";
import { LogIn, LogOut, MapPin, Building2, Search } from "lucide-react";

const SKELETON_KEYS = ["a", "b", "c", "d", "e", "f", "g", "h"];

function duration(firstIn, lastOut) {
  if (!firstIn || !lastOut) return null;
  const mins = differenceInMinutes(new Date(lastOut), new Date(firstIn));
  if (mins < 0) return null;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  if (h === 0) return `${m}m`;
  if (m === 0) return `${h}h`;
  return `${h}h ${m}m`;
}

/** One photo tile in the day-detail dialog. */
function PhotoTile({ url, label }) {
  return (
    <div className="flex-1 min-w-[140px]">
      <AuthImg
        url={url}
        alt={label}
        className="w-full h-40 object-cover rounded-lg border border-gray-200"
        fallback={
          <div className="w-full h-40 rounded-lg border border-dashed border-gray-200 flex flex-col items-center justify-center text-gray-300">
            <User size={28} />
            <span className="text-[11px] mt-1">not available</span>
          </div>
        }
      />
      <p className="text-[11px] text-gray-500 text-center mt-1">{label}</p>
    </div>
  );
}

export default function AttendanceList() {
  const navigate = useNavigate();
  const today = format(new Date(), "yyyy-MM-dd");
  const [date, setDate]     = useState(today);
  const [rangeMode, setRangeMode] = useState(false);
  const [from, setFrom]     = useState(format(new Date(Date.now() - 6 * 864e5), "yyyy-MM-dd"));
  const [to, setTo]         = useState(today);
  const [search, setSearch] = useState("");
  const [page, setPage]     = useState(1);
  const [tab, setTab]       = useState("all"); // all | current | previous
  const { user } = useAuth();
  const [exportMonth, setExportMonth] = useState(new Date().toISOString().slice(0, 7));
  const [detail, setDetail] = useState(null); // clicked daily-summary row

  // A company user always looks at their OWN company — no picker, no column.
  // Vendor/super users work across companies, so they get a dropdown.
  const isCompanyUser = ["company_admin", "company_hr", "company_gate"].includes(user?.role);
  const [companyId, setCompanyId] = useState("");
  const [workerIds, setWorkerIds] = useState([]); // [] = all workers

  const { data: companies } = useQuery({
    queryKey: ["company-options"],
    queryFn:  () => api.get("/companies", { params: { per_page: 100 } }).then((r) => r.data?.data ?? r.data),
    enabled:  !isCompanyUser,
    staleTime: 5 * 60_000,
  });

  const { data: workerOptions } = useQuery({
    queryKey: ["worker-options", companyId],
    queryFn:  () => api.get("/workers-options", {
      params: { company_id: companyId || undefined },
    }).then((r) => r.data),
    staleTime: 5 * 60_000,
  });

  // Company column only earns its place when more than one can appear.
  const showCompany = !isCompanyUser && !companyId;
  // A range spans many days, so each row must say which day it is.
  const cols = 7 + (showCompany ? 1 : 0) + (rangeMode ? 1 : 0);

  // Filters shared by the list and by every export, so a download always
  // matches what is on screen.
  const scopeParams = {
    ...(rangeMode ? { from, to } : { date }),
    ...(companyId ? { company_id: companyId } : {}),
    ...(workerIds.length ? { worker_ids: workerIds.join(",") } : {}),
  };

  // Exports follow the on-screen scope: the chosen period (range when the
  // range filter is on, else the picked month) plus company / worker filters.
  const periodParams = () => (rangeMode
    ? { from, to }
    : { month: exportMonth });

  const filterParams = () => ({
    ...(companyId ? { company_id: companyId } : {}),
    ...(workerIds.length ? { worker_ids: workerIds.join(",") } : {}),
  });

  const saveBlob = (data, filename) => {
    const url = URL.createObjectURL(data);
    const a = document.createElement("a");
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  };

  const downloadCsv = async (type) => {
    const r = await api.get("/attendance/export", {
      params: { ...periodParams(), ...filterParams(), type }, responseType: "blob",
    });
    saveBlob(r.data, `truecrew-attendance-${rangeMode ? `${from}_to_${to}` : exportMonth}-${type}.csv`);
  };

  const downloadHours = async (group) => {
    const r = await api.get("/attendance/hours-report", {
      params: { ...periodParams(), ...filterParams(), group }, responseType: "blob",
    });
    saveBlob(r.data, `truecrew-hours-${group}-${rangeMode ? `${from}_to_${to}` : exportMonth}.csv`);
  };

  const openPrintable = async () => {
    const r = await api.get("/attendance/printable", {
      params: { ...periodParams(), ...filterParams() }, responseType: "blob",
    });
    window.open(URL.createObjectURL(new Blob([r.data], { type: "text/html" })), "_blank");
  };

  const deploymentParam = tab !== "all" ? tab : undefined;

  const { data, isLoading } = useQuery({
    queryKey: ["attendance-daily", scopeParams, search, page, tab],
    queryFn:  () =>
      api.get("/attendance/daily-summary", {
        params: { ...scopeParams, search: search || undefined, page, deployment: deploymentParam },
      }).then((r) => r.data),
  });

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Attendance Log</h1>
        <p className="text-sm text-gray-500 mt-0.5">
          Daily summary — one row per worker
          {user?.role === "company_gate" && user?.location_name && (
            <span className="ml-2 badge badge-green">Your gate: {user.location_name}</span>
          )}
        </p>
      </div>

      {/* ── Exports — always follow the filters set below ── */}
      <div className="card space-y-3 py-3">
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-sm font-medium text-gray-700">
            Export {rangeMode ? "range:" : "month:"}
          </span>
          {rangeMode ? (
            <span className="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5">
              {from} → {to} <span className="text-gray-400">(from the date filter)</span>
            </span>
          ) : (
            <input type="month" className="input w-44 py-1.5 text-sm" value={exportMonth}
                   onChange={(e) => setExportMonth(e.target.value)} />
          )}
          <button className="btn-secondary text-sm" onClick={() => downloadCsv("daily")}>
            <Download size={14} /> Daily CSV
          </button>
          <button className="btn-secondary text-sm" onClick={() => downloadCsv("monthly")}>
            <Download size={14} /> Totals CSV
          </button>
          <button className="btn-secondary text-sm" onClick={openPrintable}>
            <Printer size={14} /> Report (print / PDF)
          </button>
        </div>
        <div className="flex flex-wrap items-center gap-3 pt-2.5 border-t border-gray-100">
          <span className="text-sm font-medium text-gray-700">Hours &amp; wage days:</span>
          {[
            { g: "daily",   label: "Daily hours" },
            { g: "weekly",  label: "Weekly" },
            { g: "monthly", label: "Monthly" },
            { g: "summary", label: "Total summary" },
          ].map((h) => (
            <button key={h.g} className="btn-secondary text-sm" onClick={() => downloadHours(h.g)}>
              <Download size={14} /> {h.label}
            </button>
          ))}
          <span className="text-xs text-gray-400">
            8h = 1 full day · 4h = half day · overtime counted past 8h
          </span>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 border-b border-gray-200">
        {[
          { key: "all",      label: "All" },
          { key: "current",  label: "Current Workers" },
          { key: "previous", label: "Previous Workers" },
        ].map((t) => (
          <button
            key={t.key}
            onClick={() => { setTab(t.key); setPage(1); }}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              tab === t.key
                ? "border-brand-500 text-brand-700"
                : "border-transparent text-gray-500 hover:text-gray-700"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3">
        {/* Single day ↔ date range */}
        <button
          type="button"
          onClick={() => { setRangeMode((v) => !v); setPage(1); }}
          className="btn-secondary text-sm"
          title={rangeMode ? "Switch to a single day" : "Switch to a date range"}
        >
          {rangeMode ? <><Calendar size={14} /> Single day</> : <><CalendarRange size={14} /> Date range</>}
        </button>

        {rangeMode ? (
          <div className="flex items-center gap-2">
            <input type="date" value={from} max={to}
              onChange={(e) => { setFrom(e.target.value); setPage(1); }} className="input w-auto" />
            <span className="text-gray-400 text-sm">to</span>
            <input type="date" value={to} min={from}
              onChange={(e) => { setTo(e.target.value); setPage(1); }} className="input w-auto" />
          </div>
        ) : (
          <input
            type="date"
            value={date}
            onChange={(e) => { setDate(e.target.value); setPage(1); }}
            className="input w-auto"
          />
        )}

        {/* Vendors (and super admins) work across companies — pick one */}
        {!isCompanyUser && (
          <select
            className="input w-auto"
            value={companyId}
            onChange={(e) => { setCompanyId(e.target.value); setWorkerIds([]); setPage(1); }}
          >
            <option value="">All companies</option>
            {(companies ?? []).map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
        )}

        {/* One, many, or all workers */}
        <MultiSelect
          label="Workers"
          options={(workerOptions ?? []).map((w) => ({
            id: w.id,
            name: w.emp_code ? `${w.name} · #${w.emp_code}` : w.name,
            sub: w.vendor,
          }))}
          value={workerIds}
          onChange={(v) => { setWorkerIds(v); setPage(1); }}
        />

        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={15} />
          <input
            type="text"
            placeholder="Search worker..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="input pl-9 w-52"
          />
        </div>
      </div>

      <PageHint id="attendance">
        One row = one worker's day, like your attendance register. Click a row to see the
        photos and exact times. Use <b>Export CSV</b> to open any month back in Excel.
      </PageHint>

      {/* Table */}
      <div className="card p-0 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-100">
            <tr>
              <th className="text-left px-5 py-3 font-medium text-gray-500 w-14">Photo</th>
              {rangeMode && (
                <th className="text-left px-4 py-3 font-medium text-gray-500 whitespace-nowrap">Date</th>
              )}
              <th className="text-left px-5 py-3 font-medium text-gray-500">Worker</th>
              {showCompany && (
                <th className="text-left px-4 py-3 font-medium text-gray-500 hidden lg:table-cell">
                  <span className="flex items-center gap-1"><Building2 size={13} />Company</span>
                </th>
              )}
              <th className="text-left px-4 py-3 font-medium text-gray-500 hidden md:table-cell">
                <span className="flex items-center gap-1"><MapPin size={13} />Location</span>
              </th>
              <th className="text-center px-4 py-3 font-medium text-gray-500">
                <span className="flex items-center justify-center gap-1"><LogIn size={13} />First IN</span>
              </th>
              <th className="text-center px-4 py-3 font-medium text-gray-500 hidden sm:table-cell">
                <span className="flex items-center justify-center gap-1"><LogOut size={13} />Last OUT</span>
              </th>
              <th className="text-center px-4 py-3 font-medium text-gray-500 hidden sm:table-cell">Duration</th>
              <th className="text-center px-4 py-3 font-medium text-gray-500">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {isLoading && SKELETON_KEYS.map((k) => (
              <tr key={k}>
                <td colSpan={cols} className="py-3 px-5">
                  <div className="h-4 bg-gray-100 rounded animate-pulse w-3/4" />
                </td>
              </tr>
            ))}

            {!isLoading && data?.data?.length === 0 && (
              <tr>
                <td colSpan={cols} className="text-center py-12 text-gray-400">
                  No attendance records for this {rangeMode ? "range" : "date"}.
                </td>
              </tr>
            )}

            {!isLoading && data?.data?.map((row) => {
              const stillInside  = row.first_in && !row.last_out;
              const missedOut    = row.in_count > row.out_count && row.last_out;
              const dur          = duration(row.first_in, row.last_out);
              // Live gate photo of the day when one exists, else the
              // registration photo, else an initial.
              const thumbUrl = row.in_proof_id
                ? `/attendance/proof/${row.in_proof_id}`
                : (Number(row.has_photo) ? `/workers/${row.worker_id}/photo` : null);

              return (
                <tr
                  key={`${row.worker_id}-${row.work_date}`}
                  onClick={() => setDetail(row)}
                  className="hover:bg-gray-50/50 transition-colors cursor-pointer"
                >
                  {/* Live photo */}
                  <td className="pl-5 pr-1 py-2">
                    <AuthImg
                      url={thumbUrl}
                      alt={row.worker_name}
                      className="w-10 h-10 rounded-full object-cover border border-gray-200"
                      fallback={
                        <div className="w-10 h-10 rounded-full bg-brand-50 text-brand-700 flex items-center justify-center font-semibold">
                          {(row.worker_name ?? "?").charAt(0).toUpperCase()}
                        </div>
                      }
                    />
                  </td>

                  {rangeMode && (
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">
                      {format(new Date(`${row.work_date}T00:00:00`), "dd MMM")}
                      <span className="block text-[11px] text-gray-400">
                        {format(new Date(`${row.work_date}T00:00:00`), "EEE")}
                      </span>
                    </td>
                  )}

                  {/* Worker */}
                  <td className="px-5 py-3">
                    <p className="font-medium text-gray-900 leading-tight">{row.worker_name}</p>
                    {row.vendor_name && (
                      <p className="text-xs text-gray-400 mt-0.5">{row.vendor_name}</p>
                    )}
                  </td>

                  {/* Company — hidden when only one company can appear */}
                  {showCompany && (
                    <td className="px-4 py-3 text-gray-600 hidden lg:table-cell">
                      {row.company_name ?? <span className="text-gray-300">—</span>}
                    </td>
                  )}

                  {/* Location(s) */}
                  <td className="px-4 py-3 hidden md:table-cell">
                    {row.locations ? (
                      <p className="text-gray-700 text-xs leading-relaxed">{row.locations}</p>
                    ) : (
                      <span className="text-gray-300">—</span>
                    )}
                  </td>

                  {/* First IN */}
                  <td className="px-4 py-3 text-center whitespace-nowrap">
                    {row.first_in ? (
                      <span className="text-green-700 font-medium">
                        {format(new Date(row.first_in), "hh:mm a")}
                      </span>
                    ) : (
                      <span className="text-gray-300">—</span>
                    )}
                  </td>

                  {/* Last OUT */}
                  <td className="px-4 py-3 text-center whitespace-nowrap hidden sm:table-cell">
                    {row.last_out ? (
                      <span className="text-blue-700 font-medium">
                        {format(new Date(row.last_out), "hh:mm a")}
                      </span>
                    ) : (
                      <span className="text-gray-300">—</span>
                    )}
                  </td>

                  {/* Duration */}
                  <td className="px-4 py-3 text-center hidden sm:table-cell">
                    {dur ? (
                      <span className="text-gray-700 font-medium">{dur}</span>
                    ) : stillInside ? (
                      <span className="text-xs text-gray-400 italic">ongoing</span>
                    ) : (
                      <span className="text-gray-300">—</span>
                    )}
                  </td>

                  {/* Status */}
                  <td className="px-4 py-3 text-center">
                    {stillInside ? (
                      <span className="badge badge-green text-xs">Inside</span>
                    ) : missedOut ? (
                      <span className="badge badge-yellow text-xs">Incomplete</span>
                    ) : (
                      <span className="badge badge-gray text-xs">Done</span>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>

        {data?.last_page > 1 && (
          <div className="flex items-center justify-between px-5 py-4 border-t border-gray-100">
            <p className="text-xs text-gray-500">
              Showing {data.from}–{data.to} of {data.total}
            </p>
            <div className="flex gap-2">
              <button
                onClick={() => setPage((p) => Math.max(p - 1, 1))}
                disabled={page === 1}
                className="btn-secondary py-1 text-xs"
              >Prev</button>
              <button
                onClick={() => setPage((p) => p + 1)}
                disabled={page === data.last_page}
                className="btn-secondary py-1 text-xs"
              >Next</button>
            </div>
          </div>
        )}
      </div>

      {/* ── Day detail: identity vs live photos + the full story of the day ── */}
      {detail && (
        <div
          className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
          onClick={() => setDetail(null)}
        >
          <div
            className="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-start justify-between px-6 pt-5 pb-3 border-b border-gray-100">
              <div>
                <h3 className="text-lg font-bold text-gray-900">{detail.worker_name}</h3>
                <p className="text-xs text-gray-500 mt-0.5">
                  {detail.vendor_name && <>{detail.vendor_name} · </>}
                  {detail.company_name && <>{detail.company_name} · </>}
                  {format(new Date(detail.work_date), "dd MMM yyyy")}
                </p>
              </div>
              <button className="text-gray-400 hover:text-gray-600" onClick={() => setDetail(null)}>
                <X size={20} />
              </button>
            </div>

            <div className="px-6 py-4 space-y-4">
              <div className="flex flex-wrap gap-3">
                <PhotoTile
                  label="Registration photo"
                  url={Number(detail.has_photo) ? `/workers/${detail.worker_id}/photo` : null}
                />
                <PhotoTile
                  label="Aadhaar photo"
                  url={Number(detail.has_aadhaar_photo) ? `/workers/${detail.worker_id}/aadhaar-photo` : null}
                />
                <PhotoTile
                  label={`Gate photo — IN${detail.first_in ? " " + format(new Date(detail.first_in), "hh:mm a") : ""}`}
                  url={detail.in_proof_id ? `/attendance/proof/${detail.in_proof_id}` : null}
                />
                {detail.out_proof_id && (
                  <PhotoTile
                    label={`Gate photo — OUT${detail.last_out ? " " + format(new Date(detail.last_out), "hh:mm a") : ""}`}
                    url={`/attendance/proof/${detail.out_proof_id}`}
                  />
                )}
              </div>

              <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-gray-400">First IN</p>
                  <p className="font-medium text-green-700">
                    {detail.first_in ? format(new Date(detail.first_in), "hh:mm a") : "—"}
                  </p>
                </div>
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-gray-400">Last OUT</p>
                  <p className="font-medium text-blue-700">
                    {detail.last_out ? format(new Date(detail.last_out), "hh:mm a") : "—"}
                  </p>
                </div>
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-gray-400">Duration</p>
                  <p className="font-medium text-gray-800">
                    {duration(detail.first_in, detail.last_out) ??
                      (detail.first_in && !detail.last_out ? "still inside" : "—")}
                  </p>
                </div>
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-gray-400">Events</p>
                  <p className="font-medium text-gray-800">
                    {detail.in_count} IN · {detail.out_count} OUT
                  </p>
                </div>
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-gray-400">Gate(s)</p>
                  <p className="font-medium text-gray-800">{detail.locations || "—"}</p>
                </div>
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-gray-400">Method</p>
                  <p className="font-medium text-gray-800">
                    {detail.methods || "—"}
                    {detail.best_fp_score ? ` · fp ${detail.best_fp_score}` : ""}
                    {detail.best_face_score ? ` · face ${Number(detail.best_face_score).toFixed(2)}` : ""}
                  </p>
                </div>
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-gray-400">Photo identity check</p>
                  {/* Gate captures are cross-checked against the ENROLLED face
                      (async, advisory) — catches buddy-punching. */}
                  {detail.proof_face_min != null && Number(detail.proof_face_min) === 0 ? (
                    <p className="font-medium text-red-600">
                      ⚠ a capture did not match the enrolled face
                      {detail.best_proof_face_score ? ` (best ${Number(detail.best_proof_face_score).toFixed(2)})` : ""}
                    </p>
                  ) : detail.proof_face_max != null && Number(detail.proof_face_max) === 1 ? (
                    <p className="font-medium text-emerald-600">
                      ✓ matches enrolled face
                      {detail.best_proof_face_score ? ` (${Number(detail.best_proof_face_score).toFixed(2)})` : ""}
                    </p>
                  ) : (
                    <p className="font-medium text-gray-400">not checked yet</p>
                  )}
                </div>
              </div>
            </div>

            <div className="flex justify-end gap-2 px-6 py-4 border-t border-gray-100">
              <button className="btn-secondary text-sm" onClick={() => setDetail(null)}>Close</button>
              <button
                className="btn-primary text-sm"
                onClick={() => navigate(`/workers/${detail.worker_id}`)}
              >
                Full analytics →
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
