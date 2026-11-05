Integrated Business & Operations Management System

Infernix Evolv Hub \| Version 1.0 (Draft for review) \| 05 November 2026

Classification: Internal / Confidential

Owner / Business sponsor: Julaiha (julaiha@infernixx)

# Document control

  -----------------------------------------------------------------------
  Item                                Value
  ----------------------------------- -----------------------------------
  Document ID                         INF-SRS-001

  Status                              Draft requirements baseline (not
                                      signed off)

  Version                             1.0

  Scope                               13 modules; phased implementation

  Prepared for                        Infernix Evolv Hub

  Open decisions                      Accounting basis, claim policy,
                                      bank integration, infrastructure,
                                      approvers, exact statutory scope
  -----------------------------------------------------------------------

# Contents

1\. Executive summary & objectives

2\. Scope, roles, assumptions

3\. Global functional requirements

4\. Modules M01--M13

5\. Cross-module workflows

6\. Data model & integrations

7\. Non-functional requirements

8\. Reporting & acceptance

9\. Delivery plan & decisions

# 1. Executive summary & objectives

Infernix One ialah platform dalaman untuk merekod operasi syarikat
secara berpusat: transaksi masuk/keluar, tuntutan, projek dan latihan,
dokumen komersial, staf sambilan, aset, aktiviti korporat, CSR, sijil
dan credential. Sistem direka untuk perniagaan milikan tunggal pada masa
kini tanpa mengunci reka bentuk daripada perubahan entiti perniagaan di
masa hadapan.

## 1.1 Business objectives

-   Single source of truth: transaksi, dokumen dan hubungan antara modul
    tidak diduplikasi.

-   Jejak audit dari quotation → projek → invoice → bayaran → ledger →
    laporan.

-   Sokong reimburse staff, perbelanjaan dibayar terus oleh perniagaan
    dan perbelanjaan didahulukan oleh owner.

-   Akses berasaskan peranan: owner mempunyai capaian penuh;
    finance/staf hanya mengakses data yang dibenarkan.

-   Sediakan tax preparation pack dan laporan pengurusan yang boleh
    disemak oleh akauntan.

-   Simpan credential dengan integrasi vault, tanpa password/API keys
    dalam rekod biasa.

## 1.2 In scope / out of scope

  -----------------------------------------------------------------------
  In scope                            Out of scope for baseline
  ----------------------------------- -----------------------------------
  13 modul; web responsive;           Native mobile app, public customer
  attachments; PDF generator;         portal, full banking integration
  configurable approval; reports      

  Double-entry bookkeeping            Fully automated tax submission or
  foundation; reconciliation-ready    guarantee of tax deductibility
  records                             

  Encryption, backups, permissions,   Custom cryptographic password
  audit; vault integration            manager from scratch

  Possible MyInvois connector behind  Assumption that e-Invoicing is
  feature flag                        mandatory for this entity
  -----------------------------------------------------------------------

# 2. Roles, policies & assumptions

  -----------------------------------------------------------------------
  Code                    Role                    Default scope
  ----------------------- ----------------------- -----------------------
  OWN                     Owner/Super Admin       All company modules,
                                                  configurable exceptions
                                                  for sensitive secrets

  FIN                     Finance                 Finance, supplier
                                                  payments, authorized
                                                  claims/invoices,
                                                  financial reports

  PM                      Project Manager         Assigned projects,
                                                  delivery files,
                                                  assigned project budget

  HR                      HR/Admin                Staff records,
                                                  contract, certificates,
                                                  memos; no unrestricted
                                                  finance

  STF                     Staff/Part-time         Own profile, own
                                                  claims/timesheets,
                                                  assigned project
                                                  content

  AUD                     Accountant/Auditor      Time-limited
                                                  read/export accounting
                                                  records with audit

  APP                     Delegated Approver      Only assigned approval
                                                  items, scoped to
                                                  entity/type/limit
  -----------------------------------------------------------------------

Owner view-all is a business intention; secrets must additionally
require vault entitlements, re-authentication and audited access. Data
sensitivity cannot be overridden merely because a menu is visible.

## 2.1 Access control requirements

-   RBAC + record scope + action permissions
    (view/create/edit/approve/pay/export/delete).

-   Server-side authorization on every API and file download.
    Deny-by-default; no cross-staff claims or compensation disclosure.

-   Maker-checker: preparer cannot approve own payment by default; owner
    exception logged with reason.

-   Finance may see reimbursable medical amount and receipt while
    diagnosis/clinical detail is masked or restricted.

-   Configurable approval delegation with date window, thresholds and
    reason.

## 2.2 Business assumptions requiring confirmation

-   Base currency MYR; multi-currency as optional later phase.

-   Tax period, accounting basis, chart of accounts, owner
    capital/drawings and depreciation policy to be approved with
    accountant.

-   Claims mileage rate, per diem, medical entitlement, approval
    thresholds and retention policy are configurable; no hardcoded
    entitlement.

-   Financial statements are bookkeeping outputs, not automatic LHDN
    filing.

-   Existing SSM registration and business identity require validation
    before issuing production documents.

# 3. Global functional requirements

  --------------------------------------------------------------------------------------
  ID                      Requirement                            Priority
  ----------------------- -------------------------------------- -----------------------
  G-01                    Login, MFA for privileged users,       P0
                          invitation and user deactivation       

  G-02                    Configurable permission matrix by      P0
                          role/action/resource/record            

  G-03                    Company profile, bank accounts, fiscal P0
                          year and numbering sequences           

  G-04                    Central searchable documents with      P0
                          version history and access checks      

  G-05                    Notifications: assigned approvals,     P1
                          overdue invoices, renewal alerts       

  G-06                    Company-wide audit trail incl.         P0
                          create/update/export/approval/reveal   

  G-07                    Attachment validation, allowed MIME,   P0
                          malware scanning where available       

  G-08                    CSV/XLSX export per authorization; PDF P0
                          official documents                     

  G-09                    Configurable dictionaries: categories, P0
                          statuses, tax codes, project types     

  G-10                    Soft archive / immutable posted        P0
                          financial records                      

  G-11                    Global search respecting record-level  P1
                          access                                 

  G-12                    Timezone Asia/Kuala_Lumpur, MYR        P0
                          display and date consistency           
  --------------------------------------------------------------------------------------

# 4. Functional specifications by module

## M01. Finance & Accounting

Manage income, expenses, claims, reimbursements, accounting,
reconciliation and accountant export.

### Screens / navigasi

-   Finance Overview; Income/Receipts; Expense Register; Claims;
    Bills/Payables; Payments & Payment Vouchers; Bank
    Accounts/Reconciliation; General Ledger; Tax Preparation; Finance
    Settings

### Medan teras

transaction_id; transaction_date; posting_date; currency; gross_amount;
tax_amount; category_id; account_id; payment_source; payment_method;
bank_reference; project_id/activity_id; payee/payer; attachments;
status; created_by/approved_by.

### Functional requirements

  -------------------------------------------------------------------------
  ID                      Requirement / business    Priority
                          rule                      
  ----------------------- ------------------------- -----------------------
  M01-01                  Record project/training   P0
                          revenue, grants and other 
                          inflows; separate         
                          capital/loans/refunds     
                          from sales.               

  M01-02                  Expenses distinguish      P0
                          DIRECT_COMPANY,           
                          STAFF_PAID_REIMBURSABLE   
                          and OWNER_PAID.           

  M01-03                  Claims allow multiple     P0
                          lines, receipts,          
                          category, cost centre,    
                          purpose and approval      
                          chain.                    

  M01-04                  Mileage captures          P1
                          origin/destination,       
                          distance, approved rate   
                          and project/trip.         

  M01-05                  Payments support partial  P0
                          settlement and match to   
                          payable/claim/invoice.    

  M01-06                  Use balanced journal      P0
                          entries and configurable  
                          chart of accounts; block  
                          invalid posting.          

  M01-07                  Reconcile imported/manual P1
                          bank transactions and     
                          prevent duplicate         
                          matching.                 

  M01-08                  Lock closed periods;      P1
                          corrections use           
                          reversal/adjustment with  
                          traceability.             

  M01-09                  Attach tax evidence,      P0
                          export accountant pack;   
                          tax treatment flag        
                          requires accountant       
                          review.                   

  M01-10                  Distinguish capital       P0
                          assets, consumables and   
                          owner drawings from tax   
                          deductible expenses.      
  -------------------------------------------------------------------------

### Output / reporting

P&L; Trial balance; General ledger; Receivables ageing; Payables ageing;
Cash movement; Expense by category; Tax pack; Owner capital/drawings.

## M02. Projects & Training

Track every paid engagement, including small training jobs, from lead
through closure.

### Screens / navigasi

-   Lead/Opportunity; Project List & Profile; Scope & Deliverables;
    Milestones/Tasks; Team & Timesheet; Project Documents; Project
    Financials; Closeout; Training Sessions

### Medan teras

project_id; client_id; engagement_type; project_name; scope; start/end;
contract_value; billing_terms; status_delivery; status_payment;
assigned_team; cost_budget; project_documents.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M02-01                  Create project or       P0
                          training records        
                          independently from      
                          invoice creation.       

  M02-02                  Link lead, quotation,   P0
                          PO/LOA, contract,       
                          project, invoice and    
                          receipts.               

  M02-03                  Track deliverables, due P1
                          dates, completion and   
                          blockers.               

  M02-04                  Separate delivery       P0
                          status from             
                          billing/payment status. 

  M02-05                  Link direct costs,      P0
                          trips, claims and       
                          third-party expenses to 
                          project margin.         

  M02-06                  Track training session  P1
                          date, trainer, venue,   
                          participants and        
                          feedback.               

  M02-07                  Restrict PM access to   P0
                          assigned projects and   
                          explicitly shared       
                          documents.              
  -----------------------------------------------------------------------

### Output / reporting

Project pipeline; Revenue and contribution by project; Overdue
milestones; Training register.

## M03. Quotation, Billing & Document Generation

Generate controlled commercial and corporate documents using
Infernix-branded templates.

### Screens / navigasi

-   Template Library; Quotation Builder; Quotation Approval; Invoice
    Builder; Credit Notes; Receipts; Payment Voucher; PO; Memo;
    Letter/Certificate Generator

### Medan teras

document_type; document_no; template_version; issuer_profile;
client/vendor; line_items; tax_rules; terms; valid_until; status;
issue_date; revision_no; source_record_id.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M03-01                  Issue PDF quotations    P0
                          with scope lines,       
                          quantities, price,      
                          total, validity and     
                          conditions.             

  M03-02                  Convert accepted        P0
                          quotation to project    
                          and/or invoice without  
                          re-keying.              

  M03-03                  Generate invoices by    P0
                          milestone or items;     
                          track part payments and 
                          balance.                

  M03-04                  Generate receipt only   P0
                          against actual receipt  
                          posting; no duplicate   
                          number.                 

  M03-05                  Separate incoming       P0
                          client PO from outgoing 
                          vendor PO.              

  M03-06                  Generate payment        P1
                          voucher, staff memo,    
                          appointment and         
                          training certificates.  

  M03-07                  Maintain immutable      P0
                          issued snapshots,       
                          revision history and    
                          controlled void/credit. 

  M03-08                  Use configurable        P0
                          numbering, logo, bank   
                          details, contact and    
                          authorized signatory.   
  -----------------------------------------------------------------------

### Output / reporting

Quotation register; Invoice register; Document issuance history.

## M04. HR & Part-time Staff

Manage staff/part-time/freelancer records, assignment, timesheet and
approved payments.

### Screens / navigasi

-   Staff Directory; Staff Profile; Contract & NDA; Project Assignment;
    Timesheet; Compensation/Payment; Staff Documents; Memo Inbox

### Medan teras

staff_id; engagement_type; legal_name; contact; join/end; status; role;
rate_type; rate_amount; bank_details_reference; contracts; timesheet;
assigned_projects.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M04-01                  Maintain                P0
                          active/inactive staff   
                          and staff document      
                          folders.                

  M04-02                  Staff can submit and    P0
                          see own timesheets and  
                          claims only.            

  M04-03                  PM verifies timesheet;  P1
                          authorized finance      
                          calculates payment from 
                          approved rates.         

  M04-04                  Generate voucher or     P1
                          payroll handoff from    
                          approved payment batch. 

  M04-05                  Distinguish employee    P0
                          obligations from        
                          contractor invoicing    
                          after classification    
                          review.                 

  M04-06                  Mask sensitive          P0
                          identifiers/bank        
                          information outside     
                          authorized roles.       
  -----------------------------------------------------------------------

### Output / reporting

Staff roster; Timesheet summary; Part-time payment history.

## M05. Asset Management

Track purchase, ownership, custody, warranties, maintenance,
depreciation and disposal.

### Screens / navigasi

-   Asset Register; Acquisition; Assignment/Return; Maintenance;
    Disposal; Warranty & Audit

### Medan teras

asset_id; purchase_transaction_id; name; category; serial_no;
purchase_date; cost; custodian; location; status; warranty_expiry;
capitalization_class.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M05-01                  Create asset from       P1
                          qualifying              
                          expense/purchase        
                          without a second        
                          expense entry.          

  M05-02                  Support asset vs        P1
                          low-value equipment vs  
                          consumable policy.      

  M05-03                  Track assignment,       P1
                          handover, return and    
                          repair.                 

  M05-04                  Track disposals and     P2
                          audit complete          
                          financial impacts       
                          through ledger process. 

  M05-05                  Store receipts and      P1
                          warranty documents.     
  -----------------------------------------------------------------------

### Output / reporting

Asset register; Custodian listing; Warranty expiries; Asset disposal
history.

## M06. Document Centre

Provide secure versioned documents grouped by business context and
sensitivity.

### Screens / navigasi

-   Folders/Tags; Upload; Preview; Versions; Sharing; Search;
    Retention/Archive

### Medan teras

document_id; classification; owner_id; linked_entity_type/id; version;
file_name; content_hash; size; mime_type; storage_key; access_policy;
uploaded_at.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M06-01                  Allow files linked to   P0
                          project, finance,       
                          staff, asset, CSR and   
                          company.                

  M06-02                  Prevent direct public   P0
                          access; download via    
                          authorized time-limited 
                          links.                  

  M06-03                  Version and audit       P0
                          files; no silent        
                          overwrite of issued     
                          documents.              

  M06-04                  Support metadata search P0
                          but enforce row         
                          permissions.            

  M06-05                  Configurable retention  P1
                          rules and legal hold;   
                          no permanent delete     
                          without authority.      
  -----------------------------------------------------------------------

### Output / reporting

Document inventory; Recent changes; Missing required evidence.

## M07. Corporate Activities & Travel

Track company events, site visits, conferences, trips and costs.

### Screens / navigasi

-   Activity Calendar; Event/Trip Profile; Participants; Budget;
    Itinerary; Expenses; Post-event Report

### Medan teras

activity_id; type; name; location; date_start/end; organizer; purpose;
project_id; budget; attendees; documents; expenses.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M07-01                  Create trip and group   P1
                          mileage, hotels,        
                          transport, meals and    
                          expenses.               

  M07-02                  Link activity to        P1
                          client/project without  
                          duplicating costs.      

  M07-03                  Track budget vs actual  P1
                          and attach programme    
                          photos/report.          
  -----------------------------------------------------------------------

### Output / reporting

Trip cost report; Activity register.

## M08. CSR Management

Keep auditable social impact and expenses without re-recording
accounting entries.

### Screens / navigasi

-   CSR Programme; Beneficiaries; Partners/Sponsors; Volunteers; Budget
    & Spend; Evidence; Impact Summary

### Medan teras

csr_id; programme_name; date; venue; beneficiary_group; target_count;
actual_count; partner; sponsor; budget; expenses; evidence.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M08-01                  Capture programme       P1
                          objectives, partner,    
                          location and            
                          activities.             

  M08-02                  Link finance            P1
                          transactions to CSR     
                          activity instead of     
                          creating duplicate      
                          expenses.               

  M08-03                  Record in-kind items    P1
                          separately from actual  
                          cash payments.          

  M08-04                  Attach photos with      P1
                          appropriate             
                          consent/privacy         
                          handling.               
  -----------------------------------------------------------------------

### Output / reporting

CSR annual report; CSR spend; Activities and beneficiaries.

## M09. Certification & Membership

Track professional qualifications, memberships and renewals for owner
and staff.

### Screens / navigasi

-   Certificate Register; Holder Profile; Membership Register; Renewals;
    Verification Documents

### Medan teras

certificate_id; holder_id; issuer; name; credential_no; issue_date;
expiry_date; no_expiry; verification_url; status; document_id.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M09-01                  Record certification    P1
                          type, owner, evidence   
                          and verification.       

  M09-02                  Configure renewal       P1
                          schedule and reminders  
                          at 90/60/30 days.       

  M09-03                  Support certificates    P1
                          with no expiry and      
                          annual memberships.     

  M09-04                  Link renewal expense to P1
                          finance, never          
                          duplicated.             
  -----------------------------------------------------------------------

### Output / reporting

Expiring certificates; Renewal expenditure.

## M10. Procurement & Vendor Management

Control purchasing, purchase orders, vendors and recurring SaaS
commitments.

### Screens / navigasi

-   Vendor Directory; Purchase Request; Approval; Vendor PO;
    Goods/Service Received; Vendor Invoice; Subscriptions

### Medan teras

vendor_id; request_id; purchase_order_no; budget_code; requested_by;
items; approval_state; received_date; invoice_id; subscription_id;
renewal_date.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M10-01                  Maintain vendor,        P1
                          contract,               
                          tax/registration and    
                          contact records.        

  M10-02                  PR to approval to       P1
                          vendor PO to            
                          receipt/bill/payment    
                          with audit.             

  M10-03                  Prevent vendor invoice  P0
                          from being paid twice.  

  M10-04                  Track SaaS              P1
                          subscriptions by        
                          vendor, license owner,  
                          price, billing cycle    
                          and auto-renewal.       

  M10-05                  Send expiry             P1
                          notifications;          
                          distinguish service     
                          renewal from payment    
                          posting.                
  -----------------------------------------------------------------------

### Output / reporting

Vendor spending; Purchase pipeline; Subscription renewal calendar.

## M11. Reports & Analytics

Provide role-scoped operational dashboards and exportable
decision-support reports.

### Screens / navigasi

-   Owner Executive Dashboard; Finance Dashboard; Projects Dashboard;
    HR/Admin Dashboard; Export Centre

### Medan teras

metric_id; metric_name; definition; owner; source_table;
calculation_rule; refresh_time; security_scope.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M11-01                  Owner dashboard:        P0
                          revenue, costs, cash,   
                          outstanding invoices,   
                          claims, project         
                          contribution.           

  M11-02                  Finance dashboard:      P0
                          monthly P&L,            
                          categories, unpaid      
                          bills, collections,     
                          reconciliation.         

  M11-03                  Project dashboard:      P1
                          value, status, margin,  
                          due milestones and      
                          training pipeline.      

  M11-04                  Separate invoiced       P0
                          revenue, cash received, 
                          expense incurred and    
                          cash paid.              

  M11-05                  Apply backend security  P0
                          and source row          
                          permissions to all      
                          reports/exports.        

  M11-06                  Power BI integration    P2
                          later via curated       
                          read-only reporting     
                          views.                  
  -----------------------------------------------------------------------

### Output / reporting

Executive overview; Finance overview; Operations dashboard; Accountant
export.

## M12. Administration, Master Data & Security

Manage identity, roles, master data, numbering, policy, approval and
audit.

### Screens / navigasi

-   Users; Roles/Permissions; Company Settings; Fiscal Periods; Approval
    Matrix; Numbering; Categories; Notification Rules; Audit Log; Backup
    Status

### Medan teras

user_id; role_id; resource; action; record_scope; approval_level;
policy_version; effective_from; effective_to; audit_event.

### Functional requirements

  -----------------------------------------------------------------------------
  ID                      Requirement / business rule   Priority
  ----------------------- ----------------------------- -----------------------
  M12-01                  Invite/deactivate users and   P0
                          enforce MFA for               
                          finance/owner.                

  M12-02                  Role/action/resource/record   P0
                          permission configuration,     
                          deny-by-default.              

  M12-03                  Approval matrices and         P0
                          delegation are configurable   
                          and versioned.                

  M12-04                  Audit sensitive reads,        P0
                          exports, payment approval and 
                          privilege changes.            

  M12-05                  Period lock, numbering and    P0
                          master lookup values under    
                          controlled editing.           

  M12-06                  Backup monitoring and         P0
                          authorized restore exercises. 
  -----------------------------------------------------------------------------

### Output / reporting

Audit log export; User access report; Policy history.

## M13. Credential & Secrets Management

Maintain an inventory of systems and access ownership; actual
passwords/tokens are stored in an established secrets vault.

### Screens / navigasi

-   Credential Inventory; System Accounts; Vault Links; Access Requests;
    Rotation Schedule; Credential Access Audit; Revocation Checklist

### Medan teras

credential_id; system; category; environment; url; username_reference;
owner; project_id; vault_item_reference; sensitivity; rotation_due;
expiry_date; status.

### Functional requirements

  -----------------------------------------------------------------------
  ID                      Requirement / business  Priority
                          rule                    
  ----------------------- ----------------------- -----------------------
  M13-01                  Classify cloud, BI,     P1
                          database, development,  
                          AI, corporate, client   
                          and API credentials.    

  M13-02                  Store metadata and      P0
                          vault reference only;   
                          no plaintext            
                          password/API key in     
                          standard database, logs 
                          or backups.             

  M13-03                  Vault access must be    P1
                          independently           
                          authorized; sensitive   
                          reveal requires         
                          re-authentication and   
                          audit.                  

  M13-04                  Track                   P1
                          request/grant/revoke,   
                          joiner-mover-leaver     
                          process and rotation    
                          dates.                  

  M13-05                  Do not expose passwords P0
                          in reports, exports,    
                          notifications or search 
                          indexes.                

  M13-06                  Support client-specific P1
                          segregation and         
                          non-shared service      
                          account policy.         
  -----------------------------------------------------------------------

### Output / reporting

Credential inventory (no secrets); Expired/rotation due; Access events.

# 5. Cross-module workflows

  --------------------------------------------------------------------------------
  ID                Workflow          Steps               Controls
  ----------------- ----------------- ------------------- ------------------------
  WF-01             Staff             Claim Draft →       Rejected item can be
                    reimbursement     Submitted →         corrected/resubmitted;
                                      Supervisor/Owner    receipt mandatory by
                                      Review →            policy.
                                      Approved/Rejected → 
                                      Finance Payable →   
                                      Payment Posted →    
                                      Closed              

  WF-02             Company direct    Purchase Request    Asset event must not
                    purchase          (optional) → Vendor generate second expense.
                                      PO → Vendor         
                                      Bill/Receipt →      
                                      Payment → Ledger →  
                                      Asset if qualifying 

  WF-03             Owner-paid        Owner Payment →     Owner drawings are
                    expenses          Expense Assessment  separate from deductible
                                      → Reimbursement OR  expenses.
                                      Owner Funding       
                                      Classification →    
                                      Ledger              

  WF-04             Sales to cash     Lead → Quotation →  Invoice can remain
                                      Accepted → Project  unpaid after project
                                      → Invoice →         completion.
                                      Partial/Full        
                                      Receipts → Close    

  WF-05             Part-time         Staff onboard →     Rates locked to
                    staffing          Assign project →    agreement version.
                                      Submit timesheet →  
                                      Verify → Calculate  
                                      payment → Approve → 
                                      Payment voucher     

  WF-06             Subscription      Subscription        Renewal alert does not
                    renewal           created →           automatically create
                                      90/60/30-day alert  expense.
                                      → Review →          
                                      Renew/Cancel →      
                                      Supplier            
                                      invoice/payment     

  WF-07             Credential access Access request →    Plaintext secret never
                                      Resource owner      returned by Infernix
                                      approval → Vault    reports.
                                      entitlement grant → 
                                      Audit → Revoke at   
                                      offboarding         

  WF-08             Monthly closing   Import/record       All journal entries must
                                      entries → Bank      balance.
                                      match → Reconcile → 
                                      Trial balance       
                                      review → Lock       
                                      period → Adjust     
                                      with approval       
  --------------------------------------------------------------------------------

## 5.1 State transitions & errors

Transactions and documents require explicit statuses (Draft, Submitted,
Pending Approval, Approved, Rejected, Cancelled, Paid/Settled, Posted,
Archived where relevant). All transitions validate role, prerequisite
evidence and concurrency. Errors must not result in duplicate posting.
Use idempotency keys for payment/invoice integration endpoints.

# 6. Data model & integration

## 6.1 Core relational entities

  ------------------------------------------------------------------------
  Domain                  Primary tables           Key relationships
                          (indicative)             
  ----------------------- ------------------------ -----------------------
  Identity                users, roles,            users → assignments,
                          permissions, user_roles, approvals, audit
                          user_scopes              

  CRM/Delivery            clients, vendors,        client 1:N projects;
                          projects, trainings,     project 1:N tasks
                          tasks, project_members   

  Commercial              quotations,              quotation →
                          quotation_lines,         project/invoice;
                          invoices, invoice_lines, receipt N:M invoice
                          receipts,                allocation
                          receipt_allocations      

  Finance                 chart_accounts,          journal 1:N lines;
                          journals, journal_lines, payments allocate to
                          expense_lines, claims,   payables/claims
                          claim_lines, payments,   
                          bank_transactions        

  HR                      staff_profiles,          staff 1:N timesheets;
                          engagements, timesheets, project N:M staff
                          assignments              

  Assets & Corporate      assets,                  asset acquisition
                          asset_assignments,       references expense/bill
                          activities,              
                          csr_programmes,          
                          subscriptions,           
                          certifications           

  Documents & Security    documents,               document polymorphic
                          document_versions,       association via
                          entity_document_links,   validated link table
                          access_requests,         
                          credentials_metadata,    
                          audit_events             
  ------------------------------------------------------------------------

## 6.2 Architecture constraints

-   Proposed stack: Laravel modular monolith, PostgreSQL, Redis
    queue/cache, S3-compatible private object storage, HTML/PDF
    rendering, encrypted backups.

-   One transactional database for authoritative business records,
    curated reporting views for Power BI; no direct dashboard writeback
    to ledger.

-   Vault integration via Bitwarden/1Password/managed secrets service
    subject to business selection. Do not design in-house plaintext
    password storage.

-   Support scheduled notifications through background jobs; email
    delivery provider selected at deployment.

-   e-Invois/MyInvois adapter optional, subject to verified eligibility
    and latest API/business requirements.

-   External integrations require retry, event logs, rate limits and
    replay-safe synchronization.

## 6.3 Data integrity rules

-   Every finance document has unique company + type + sequence, with
    cancelled numbers retained.

-   For each posted journal: total debit = total credit; no deletion,
    only reversal.

-   No two payment allocations can exceed payable/invoice balance.

-   Amounts recorded in exact decimal values, not binary floats.

-   Uploaded file metadata, checksum and storage path must remain
    consistent; attachment permissions inherit from business record plus
    document sensitivity.

-   Client environments and credentials require explicit project/client
    scopes.

# 7. Non-functional requirements

  -----------------------------------------------------------------------
  ID                      Attribute               Acceptance target
                                                  (proposal)
  ----------------------- ----------------------- -----------------------
  NFR-01                  Security                HTTPS/TLS, MFA for
                                                  privileged roles,
                                                  secure sessions,
                                                  encryption at rest as
                                                  supported; secrets
                                                  vault

  NFR-02                  Authorization           Every protected
                                                  endpoint denies
                                                  unauthorized roles,
                                                  including direct URL
                                                  and file access

  NFR-03                  Auditability            Capture who, what,
                                                  when, entity,
                                                  before/after for
                                                  allowed fields; redact
                                                  secret/PII

  NFR-04                  Availability            Initial target ≥99.5%
                                                  monthly service
                                                  availability excluding
                                                  approved maintenance

  NFR-05                  Performance             95th percentile common
                                                  list/detail requests
                                                  \<3 seconds under
                                                  agreed test load

  NFR-06                  Backup & restore        Daily encrypted backup;
                                                  RPO ≤24h, RTO ≤8h
                                                  initially; quarterly
                                                  restore test

  NFR-07                  Data privacy            Least privilege,
                                                  sensitive
                                                  medical/identity access
                                                  minimized, retention
                                                  and consent controls

  NFR-08                  Compatibility           Responsive
                                                  desktop/tablet; latest
                                                  major Chrome/Edge
                                                  versions

  NFR-09                  Accessibility           Keyboard navigation,
                                                  labels, sufficient
                                                  contrast and readable
                                                  error messages

  NFR-10                  Observability           Error logs, trace IDs,
                                                  security event alerts,
                                                  backup-job monitoring

  NFR-11                  Maintainability         Versioned schema
                                                  migration, automated
                                                  tests, documented API
                                                  and release procedure

  NFR-12                  Localization            MYR, Malaysia
                                                  addresses,
                                                  Asia/Kuala_Lumpur,
                                                  BM/English labels where
                                                  defined
  -----------------------------------------------------------------------

# 8. Reporting catalog & acceptance tests

## 8.1 Report definitions

  -----------------------------------------------------------------------
  Report                              Definition / validation
  ----------------------------------- -----------------------------------
  Revenue vs cash receipts            Invoice revenue shown separately
                                      from cash collected; period basis
                                      clearly labeled

  Project margin                      Net project revenue less linked
                                      direct costs; overhead excluded
                                      unless allocation policy applied

  Outstanding receivables             Invoice gross less receipt
                                      allocations and eligible credits as
                                      of report date

  Expenses by category                Posted categorized business
                                      expenses; owner drawings excluded

  Claim outstanding                   Approved claims less posted
                                      reimbursements; distinguish pending
                                      approval

  Asset register                      Total purchased, assigned, disposed
                                      and policy-driven capital assets

  Tax preparation pack                Journal, accounts, vouchers,
                                      receipts, invoice evidence, bank
                                      movement and review flags

  Credential renewal                  System, owner, rotation due and
                                      status only; no secret or password
                                      export
  -----------------------------------------------------------------------

## 8.2 UAT scenarios

  -----------------------------------------------------------------------
  Test ID                 User action             Expected result
  ----------------------- ----------------------- -----------------------
  UAT-01                  Staff opens another     403/denied; no data or
                          staff claim via changed attachment exposure
                          URL                     

  UAT-02                  Staff submits mileage   Single expense
                          claim then approval and accounting impact;
                          payout                  payment linked; audit
                                                  complete

  UAT-03                  Owner purchases laptop  Vendor payment + asset
                          with company account    registration; no double
                                                  counting

  UAT-04                  Invoice RM10,000 paid   Invoice outstanding =
                          RM3,000                 RM7,000; receipt linked

  UAT-05                  Finance posts           Posting rejected; no
                          unbalanced journal      partial journal

  UAT-06                  Generate quotation →    Client and scope
                          convert to project →    retained; unique
                          issue invoice           document refs; audit

  UAT-07                  Staff logs time on      Rejected unless
                          unassigned project      role-based exception
                                                  exists

  UAT-08                  User attempts secret    No password/API secret
                          through report export   in export or log

  UAT-09                  Close accounting period Edit blocked;
                          then edit posted        authorized
                          transaction             adjustment/reversal
                                                  available

  UAT-10                  Upload document and     Access denied even with
                          access without          copied direct file URL
                          authorization           

  UAT-11                  Approve own payment     Rejected or flagged for
                          without delegated       owner explicit override
                          authority               

  UAT-12                  Renew subscription      Renewal creates
                                                  scheduled event;
                                                  finance transaction
                                                  only when bill/payment
                                                  recorded
  -----------------------------------------------------------------------

# 9. Implementation roadmap & open decisions

  ----------------------------------------------------------------------------
  Phase                   Modules                      Exit criteria
  ----------------------- ---------------------------- -----------------------
  P1 Foundation / MVP     M12 security/config; M06     Owner/Finance/staff
                          documents; M01 core; M03     access tested; claim →
                          basic quote/invoice/PV; M11  payment → ledger;
                          basic dashboards             invoice → receipt; core
                                                       exports

  P2 Commercial delivery  M02 projects/training; M04   Project finances
                          HR/timesheet; M03 expanded   linked; staff payment
                          templates                    flow; delivery docs

  P3 Business operations  M05 assets; M07 trips; M08   No duplicate
                          CSR; M09 certifications; M10 transactions; renewal
                          procurement/subscriptions;   alerts; full scoped
                          M13 vault integration        access

  P4 Advanced automation  Bank matching improvements;  Documented
                          advanced reporting; optional reconciliations, UAT
                          API integrations and         sign-off and runbooks
                          e-Invoice                    
  ----------------------------------------------------------------------------

## 9.1 Decisions before developer sign-off

  -----------------------------------------------------------------------
  Decision                Provisional proposal    Owner sign-off
  ----------------------- ----------------------- -----------------------
  Finance policy          MYR; double-entry;      Pending
                          accountant-approved COA 
                          and owner drawings      

  Claim limits            Configurable            Pending
                          categories, rates,      
                          mandatory evidence,     
                          approval chain          

  Roles                   7 default roles;        Pending
                          per-action permissions; 
                          owner exception         
                          controlled              

  Source of truth         PostgreSQL + private    Pending
                          object storage          

  Vault product           Use established secrets Pending
                          vault; no plaintext     
                          secret store            

  Templates               Approved logo, business Pending
                          address, issuer         
                          signature and legal     
                          wording                 

  Deployment              Choose hosted/cloud     Pending
                          environment, backup and 
                          disaster recovery       

  Statutory configuration Have accountant verify  Pending
                          current tax, retention, 
                          payroll and e-Invoice   
                          rules                   

  Notifications           Choose email            Pending
                          domain/provider and     
                          reminder preferences    

  Data migration          Identify starting       Pending
                          records, opening        
                          balances, source        
                          spreadsheets            
  -----------------------------------------------------------------------

## 9.2 Definition of Done

-   Business owner signs off SRS version and prioritized backlog.

-   Each P0 requirement has passing automated/security test and UAT
    evidence.

-   General ledger balances and sample invoices, claims, payments
    reconcile.

-   Role tests confirm no privilege escalation, secret exposure or
    unauthorized file access.

-   Backup restore tested; monitoring, support SOP and account recovery
    documented.

-   Handover contains source repository, deployment instructions,
    schema, API docs and admin manual.

Note: This SRS is a solution blueprint and business requirements draft.
It is not an attestation of regulatory compliance; statutory
calculations and tax treatment must be independently reviewed before
production use.
