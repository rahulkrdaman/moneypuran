"use client";
import { useState, useEffect } from "react";
import { Plus, Search, Edit, Shield, Mail } from "lucide-react";

interface User { id:string;email:string;firstName:string;lastName:string;username:string;role:string;isActive:boolean;createdAt:string;_count?:{posts:number} }
const ROLE_COLORS:Record<string,string> = { SUPER_ADMIN:"bg-purple-100 text-purple-700", ADMIN:"bg-red-100 text-red-700", EDITOR:"bg-blue-100 text-blue-700", AUTHOR:"bg-green-100 text-green-700", VIEWER:"bg-gray-100 text-gray-600" };

export default function UsersPage() {
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");

  useEffect(() => {
    fetch("/api/users").then(r=>r.json()).then(d=>{setUsers(d.data||[]);setLoading(false);});
  }, []);

  const filtered = users.filter(u => u.email.includes(search) || `${u.firstName} ${u.lastName}`.toLowerCase().includes(search.toLowerCase()));

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div><h1 className="text-2xl font-heading font-bold">Users</h1><p className="text-muted-foreground text-sm">{users.length} total users</p></div>
        <button className="btn-primary text-sm flex items-center gap-1.5"><Plus className="h-4 w-4" />Invite User</button>
      </div>
      <div className="card p-4">
        <div className="relative"><Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" /><input value={search} onChange={e=>setSearch(e.target.value)} className="input pl-9 h-9 text-sm w-72" placeholder="Search users..." /></div>
      </div>
      <div className="card overflow-hidden">
        <table className="w-full text-sm">
          <thead><tr className="border-b border-border bg-muted/50 text-left">
            <th className="p-3 font-medium text-muted-foreground">User</th>
            <th className="p-3 font-medium text-muted-foreground">Role</th>
            <th className="p-3 font-medium text-muted-foreground text-center">Posts</th>
            <th className="p-3 font-medium text-muted-foreground text-center">Status</th>
            <th className="p-3 font-medium text-muted-foreground text-right">Actions</th>
          </tr></thead>
          <tbody>
            {loading ? Array.from({length:5}).map((_,i)=>(<tr key={i}><td colSpan={5} className="p-3"><div className="h-4 skeleton rounded w-full" /></td></tr>))
            : filtered.map(user=>(
              <tr key={user.id} className="border-b border-border/50 hover:bg-muted/30 transition-colors">
                <td className="p-3"><div className="flex items-center gap-3"><div className="h-9 w-9 rounded-full bg-brand-100 dark:bg-brand-950 flex items-center justify-center font-bold text-brand-600">{user.firstName[0]}</div><div><p className="font-medium">{user.firstName} {user.lastName}</p><p className="text-xs text-muted-foreground">{user.email}</p></div></div></td>
                <td className="p-3"><span className={`badge text-xs px-2 py-0.5 ${ROLE_COLORS[user.role]||ROLE_COLORS.VIEWER}`}><Shield className="h-3 w-3 inline mr-1" />{user.role.replace("_"," ")}</span></td>
                <td className="p-3 text-center">{user._count?.posts||0}</td>
                <td className="p-3 text-center"><span className={`badge text-xs ${user.isActive?"bg-green-100 text-green-700":"bg-red-100 text-red-600"}`}>{user.isActive?"Active":"Inactive"}</span></td>
                <td className="p-3"><div className="flex justify-end gap-1"><button className="h-7 w-7 flex items-center justify-center rounded hover:bg-muted text-muted-foreground hover:text-brand-600 transition-colors"><Edit className="h-3.5 w-3.5" /></button><button className="h-7 w-7 flex items-center justify-center rounded hover:bg-muted text-muted-foreground hover:text-blue-600 transition-colors"><Mail className="h-3.5 w-3.5" /></button></div></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}