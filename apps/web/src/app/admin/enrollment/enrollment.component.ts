import { decodeSessionBlob } from '../../core/utils/session-blob';
import { Component } from '@angular/core';
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {TopComponent} from '../../partials/top/top.component';
import {SideComponent} from '../../partials/side/side.component';
import {TuiIcon} from '@taiga-ui/core';
import {CommonModule, DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {CourseEnrollmentComponent} from '../courses/course-enrollment/course-enrollment.component';
type EnrollmentType = 'By Department' | 'By Selected' | 'By Role' | 'Single' | 'By Invite';

interface Learner {
  first: string;
  last: string;
  email: string;
  department: string;
  role: string;
  selected?: boolean;
}

interface Enrollment {
  name: string;
  email: string;
  course: string;
  department?: string;
  type: EnrollmentType;
  status: 'Active' | 'Pending';
  date: Date;
}
@Component({
  selector: 'app-enrollment',
  standalone: true,
  imports: [
    TopComponent,
    SideComponent,
    TuiIcon,
    CommonModule,
    FormsModule,
    DatePipe,
    AdmintopComponent,
    CourseEnrollmentComponent
  ],
  templateUrl: './enrollment.component.html',
  styleUrl: './enrollment.component.css'
})
export class EnrollmentComponent {
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {
  }
  ui_controls = {
    is_loading: false,
    no_recent: false,
    viewing_dashboard: true,
    viewing_recent_orders: false,
    viewing_menu: false
  };
// sample learners (Nigerian names + domain)
  learners: Learner[] = [
    { first: 'Adebayo', last: 'Oluwadamilola', email: 'adebayo@ifitness.ng', department: 'Human Resources', role: 'HR Officer' },
    { first: 'Chinedu', last: 'Okeke', email: 'chinedu@ifitness.ng', department: 'IT', role: 'Software Engineer' },
    { first: 'Fatima', last: 'Bello', email: 'fatima@ifitness.ng', department: 'Finance', role: 'Accountant' },
    { first: 'Tunde', last: 'Balogun', email: 'tunde@ifitness.ng', department: 'Marketing', role: 'Brand Manager' },
    { first: 'Ngozi', last: 'Eze', email: 'ngozi@ifitness.ng', department: 'Customer Service', role: 'Support Officer' },
    { first: 'Halima', last: 'Suleiman', email: 'halima@ifitness.ng', department: 'Operations', role: 'Customer Support' },
    { first: 'Kunle', last: 'Adewale', email: 'kunle@ifitness.ng', department: 'Design', role: 'Product Designer' }
  ];

  // departments, roles, courses
  departments = Array.from(new Set(this.learners.map(l => l.department)));
  rolesList = Array.from(new Set(this.learners.map(l => l.role)));
  coursesList = ['Workplace Safety', 'Leadership 101', 'Customer Service Mastery', 'IT Security Basics', 'Advanced Fitness Training', 'Nutrition Essentials'];

  // bind models
  deptSelected = '';
  courseSelectedDept = '';

  courseSelectedBulk = '';

  roleSelected = '';
  courseSelectedRole = '';

  singleEmail = '';
  courseSelectedSingle = '';

  inviteEmails = '';
  courseSelectedInvite = '';

  // enrollments
  enrolled: Enrollment[] = [];

  // summary search
  summarySearch = '';

  // helpers
  private normalizeEmail(e: string): string {
    const trimmed = e.trim();
    if (!trimmed) return '';
    // if user provides no domain, append @ifitness.ng
    if (!/@/.test(trimmed)) return `${trimmed}@ifitness.ng`;
    return trimmed.toLowerCase();
  }

  private alreadyEnrolled(email: string, course: string): boolean {
    return this.enrolled.some(en => en.email.toLowerCase() === email.toLowerCase() && en.course === course);
  }

  private pushEnrollment(name: string, email: string, course: string, department: string | undefined, type: EnrollmentType, status: 'Active' | 'Pending' = 'Active') {
    const normalized = this.normalizeEmail(email);
    if (!normalized) return;
    if (!course) {
      window.alert('Please select a course.');
      return;
    }
    if (this.alreadyEnrolled(normalized, course)) {
      // don't duplicate; optionally update status/date
      return;
    }
    const item: Enrollment = {
      name,
      email: normalized,
      course,
      department,
      type,
      status,
      date: new Date()
    };
    this.enrolled.unshift(item);
  }

  /* ---------- Enroll methods ---------- */

  enrollDepartment() {
    if (!this.deptSelected) { alert('Select a department first.'); return; }
    if (!this.courseSelectedDept) { alert('Select a course first.'); return; }
    const targets = this.learners.filter(l => l.department === this.deptSelected);
    if (targets.length === 0) { alert('No learners found in this department.'); return; }
    targets.forEach(l => {
      this.pushEnrollment(`${l.first} ${l.last}`, l.email, this.courseSelectedDept, l.department, 'By Department', 'Active');
    });
    alert(`Enrolled ${targets.length} learner(s) from ${this.deptSelected} to ${this.courseSelectedDept}.`);
  }

  toggleAll(ev: any) {
    const checked = ev.target.checked;
    this.learners.forEach(l => l.selected = checked);
  }

  enrollSelected() {
    if (!this.courseSelectedBulk) { alert('Select a course for bulk enrollment.'); return; }
    const selected = this.learners.filter(l => l.selected);
    if (selected.length === 0) { alert('No learners selected.'); return; }
    selected.forEach(l => this.pushEnrollment(`${l.first} ${l.last}`, l.email, this.courseSelectedBulk, l.department, 'By Selected', 'Active'));
    this.success_notification(`Enrolled ${selected.length} selected learner(s) to ${this.courseSelectedBulk}.`);
    // optional: clear selection
    this.learners.forEach(l => l.selected = false);
  }

  enrollByRole() {
    if (!this.roleSelected) { alert('Select a role first.'); return; }
    if (!this.courseSelectedRole) { alert('Select a course first.'); return; }
    const targets = this.learners.filter(l => l.role === this.roleSelected);
    if (targets.length === 0) { alert('No learners found for this role.'); return; }
    targets.forEach(l => this.pushEnrollment(`${l.first} ${l.last}`, l.email, this.courseSelectedRole, l.department, 'By Role', 'Active'));
    this.success_notification(`Enrolled ${targets.length} learner(s) with role ${this.roleSelected} to ${this.courseSelectedRole}.`);
  }

  enrollSingle() {
    if (!this.singleEmail) { alert('Enter an email address.'); return; }
    if (!this.courseSelectedSingle) { alert('Select a course.'); return; }
    const email = this.normalizeEmail(this.singleEmail);
    // find learner to attach department/name if present; otherwise set unknown
    const found = this.learners.find(l => l.email.toLowerCase() === email.toLowerCase());
    const name = found ? `${found.first} ${found.last}` : email.split('@')[0];
    const department = found ? found.department : undefined;
    if (this.alreadyEnrolled(email, this.courseSelectedSingle)) { alert('This user is already enrolled for that course.'); return; }
    this.pushEnrollment(name, email, this.courseSelectedSingle, department, 'Single', 'Active');
    this.success_notification(`Enrolled ${email} to ${this.courseSelectedSingle}.`);
    this.singleEmail = '';
  }

  sendInvites() {
    if (!this.inviteEmails) { alert('Enter one or more emails.'); return; }
    if (!this.courseSelectedInvite) { alert('Select a course.'); return; }
    // split by comma or whitespace
    const raw = this.inviteEmails.split(/[,;\n]+/).map(s => s.trim()).filter(Boolean);
    if (raw.length === 0) { alert('No valid emails found.'); return; }
    let added = 0;
    raw.forEach(r => {
      const email = this.normalizeEmail(r);
      if (!this.alreadyEnrolled(email, this.courseSelectedInvite)) {
        // invited => mark Pending
        const found = this.learners.find(l => l.email.toLowerCase() === email.toLowerCase());
        const name = found ? `${found.first} ${found.last}` : email.split('@')[0];
        const department = found ? found.department : undefined;
        this.pushEnrollment(name, email, this.courseSelectedInvite, department, 'By Invite', 'Pending');
        added++;
      }
    });
    alert(`Invitations sent for ${added} address(es) to ${this.courseSelectedInvite}.`);
    this.inviteEmails = '';
  }

  /* ---------- Summary search / helpers ---------- */
  filteredEnrolled() {
    if (!this.summarySearch || this.summarySearch.trim() === '') return this.enrolled;
    const q = this.summarySearch.toLowerCase().trim();
    return this.enrolled.filter(e =>
      e.name.toLowerCase().includes(q) ||
      e.email.toLowerCase().includes(q) ||
      e.course.toLowerCase().includes(q) ||
      (e.department || '').toLowerCase().includes(q) ||
      e.type.toLowerCase().includes(q)
    );
  }

  clearSummarySearch() {
    this.summarySearch = '';
  }

  session_data: any = ""
  user_session = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    is_2fa: false,
    is_active: false
  };
  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  sign_out(): void {
    this.success_notification("User logged out successfully.");
    this.router.navigate(['/']).then(r => console.log(r));
  }
  goBack() {
    this.router.navigate(['/administration']).then(r => console.log(r));
  }
}
