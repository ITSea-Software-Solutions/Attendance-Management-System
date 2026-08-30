import { useQuery } from "@tanstack/react-query";
import { format } from "date-fns";
import api from "@/lib/axios";
import { useOrgScope } from "@/lib/scope";
import { AlertTriangle } from "lucide-react";
import { useState } from "react";

export default function AttendanceExceptions() {
  const { showCompany } = useOrgScope();
  const [date, setDate] = useState(format(new Date(), "yyyy-MM-dd"));

  const { data, isLoading } = useQuery({
    queryKey: ["exceptions", date],
    queryFn:  () => api.get("/attendance/exceptions", { params: { date } }).then((r) => r.data),
  });

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Still Inside</h1>
          <p className="text-sm text-gray-500 mt-0.5">Workers with IN but missing OUT</p>
        </div>
        <input
          type="date"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          className="input w-auto"
        />
      </div>

      <div className="card">
        {isLoading ? (
          <div className="animate-pulse space-y-3">
            {[...Array(4)].map((_, i) => <div key={i} className="h-12 bg-gray-100 rounded" />)}
          </div>
        ) : data?.missing_out?.length === 0 ? (
          <div className="text-center py-12">
            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
              <AlertTriangle className="w-8 h-8 text-green-500" />
            </div>
            <p className="text-gray-500">No exceptions for {date}. All workers have marked OUT.</p>
          </div>
        ) : (
          <div className="space-y-2">
            <p className="text-sm text-amber-700 font-medium mb-4">
              {data?.total} worker(s) marked IN but have not marked OUT yet.
            </p>
            {data?.missing_out?.map((log) => (
              <div key={log.id || `${log.worker_id}-${log.company_id}`}
                className="flex items-center justify-between p-4 bg-amber-50 rounded-xl border border-amber-200">
                <div>
                  <p className="font-medium text-gray-900">{log.worker?.name}</p>
                  {showCompany() && (
                    <p className="text-xs text-gray-500">{log.company?.name}</p>
                  )}
                </div>
                <span className="badge badge-yellow">Missing OUT</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
