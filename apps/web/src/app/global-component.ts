import {version} from 'ckeditor5';

export class GlobalComponent {
  static updateCourseStatus: string;
  static deleteCourse: string;


  constructor() {}
  public static baseURL = 'https://api.centrallms.com' // test
  public static scormBaseURL = 'https://api.centrallms.com/user' // test
  public static BaseURL = 'https://api.centrallms.com/user/' // test
  public static redirectUri = 'https://api.centrallms.com/user' // test

  public static integration_callback = GlobalComponent.baseURL +'/user/integration-callback';
  public static learningPathEnrollment = GlobalComponent.baseURL +'/user/learning-path-enrollment';

  public static UserLogin = GlobalComponent.baseURL +'/authentication/login';
  public static UserRegister = GlobalComponent.baseURL +'/authentication/register';
  public static UserValidate = GlobalComponent.baseURL +'/authentication/validate';
  public static EmailValidate = GlobalComponent.baseURL +'/authentication/validate-email';
  public static UserReset = GlobalComponent.baseURL +'/authentication/reset';
  public static UserConfirm = GlobalComponent.baseURL +'/authentication/confirm';
  public static UserList = GlobalComponent.baseURL +'/user/user_list';
  public static InstructorList = GlobalComponent.baseURL +'/user/instructor';
  public static SingleUser = GlobalComponent.baseURL +'/user/single';
  public static UserStatistics = GlobalComponent.baseURL +'/user/statistics';
  public static UserNotes = GlobalComponent.baseURL +'/user/notes/list';
  public static UserCreate = GlobalComponent.baseURL +'/user/create';
  public static UserUpdate = GlobalComponent.baseURL +'/user/update';
  public static UserStatus = GlobalComponent.baseURL +'/user/status';
  public static UserPassword = GlobalComponent.baseURL +'/user/change_password';

  // media
  public static MediaUpload = GlobalComponent.baseURL +'/user/media/upload';
  public static MediaStorage = GlobalComponent.baseURL +'/user/media/storage';
  public static MediaVersions = GlobalComponent.baseURL +'/user/media/versions';
  public static MediaList = GlobalComponent.baseURL +'/user/media/read';
  public static deleteMedia = GlobalComponent.baseURL +'/user/media/delete';

  // Course
  public static createCourse = GlobalComponent.baseURL +'/user/courses/create'; // Create
  public static updateCourse = GlobalComponent.baseURL +'/user/courses/update'; // update
  public static listCourse = GlobalComponent.baseURL +'/user/courses/list'; // listing
  public static swapStatusCourse = GlobalComponent.baseURL +'/user/courses/status'; // delete
  public static singleCourse = GlobalComponent.baseURL +'/user/courses/single'; // single
  public static activeCourse = GlobalComponent.baseURL +'/user/courses/active'; // active
  public static courseInsights = GlobalComponent.baseURL +'/user/courses/insights'; // insight
  public static courseDuplicate = GlobalComponent.baseURL +'/user/courses/duplicate'; // insight

  // Branch
  public static createBranch = GlobalComponent.baseURL +'/dictionary/branches/create'; // Create
  public static updateBranch = GlobalComponent.baseURL +'/dictionary/branches/update'; // update
  public static listBranch = GlobalComponent.baseURL +'/dictionary/branches/list'; // listing
  public static switchBranch = GlobalComponent.baseURL +'/dictionary/branches/status'; // delete
  public static singleBranch = GlobalComponent.baseURL +'/dictionary/branches/single'; // single
  public static activeBranch = GlobalComponent.baseURL +'/dictionary/branches/active'; // single

  // Departments
  public static createDepartment = GlobalComponent.baseURL +'/dictionary/departments/create'; // Create
  public static updateDepartment = GlobalComponent.baseURL +'/dictionary/departments/update'; // update
  public static listDepartment = GlobalComponent.baseURL +'/dictionary/departments/list'; // listing
  public static switchDepartment = GlobalComponent.baseURL +'/dictionary/departments/status'; // delete
  public static singleDepartment = GlobalComponent.baseURL +'/dictionary/departments/single'; // single
  public static activeDepartment = GlobalComponent.baseURL +'/dictionary/departments/active'; // single

  // Teams
  public static createTeam = GlobalComponent.baseURL +'/dictionary/team/create'; // Create
  public static UpdateTeam = GlobalComponent.baseURL +'/dictionary/team/update'; // update
  public static listTeam = GlobalComponent.baseURL +'/dictionary/team/list'; // listing
  public static switchTeam = GlobalComponent.baseURL +'/dictionary/team/status'; // delete
  public static singleTeam = GlobalComponent.baseURL +'/dictionary/team/single'; // single
  public static activeTeam = GlobalComponent.baseURL +'/dictionary/team/active'; // single


  // Job Titles
  public static createJobTitle = GlobalComponent.baseURL +'/dictionary/jobtitles/create'; // Create
  public static UpdateJobTitle = GlobalComponent.baseURL +'/dictionary/jobtitles/update'; // update
  public static listJobTitle = GlobalComponent.baseURL +'/dictionary/jobtitles/list'; // listing
  public static switchJobTitle = GlobalComponent.baseURL +'/dictionary/jobtitles/status'; // delete
  public static singleJobTitle = GlobalComponent.baseURL +'/dictionary/jobtitles/single'; // single
  public static activeJobTitle = GlobalComponent.baseURL +'/dictionary/jobtitles/active'; // single


  // Levels
  public static createLevels = GlobalComponent.baseURL +'/dictionary/levels/create'; // Create
  public static UpdateLevels = GlobalComponent.baseURL +'/dictionary/levels/update'; // update
  public static listLevels = GlobalComponent.baseURL +'/dictionary/levels/list'; // listing
  public static switchLevels = GlobalComponent.baseURL +'/dictionary/levels/status'; // delete
  public static singleLevels = GlobalComponent.baseURL +'/dictionary/levels/single'; // single
  public static activeLevels = GlobalComponent.baseURL +'/dictionary/levels/active'; // single



  // Category
  public static createCategory = GlobalComponent.baseURL +'/dictionary/categories/create'; // Create
  public static UpdateCategory = GlobalComponent.baseURL +'/dictionary/categories/update'; // update
  public static listCategory = GlobalComponent.baseURL +'/dictionary/categories/list'; // listing
  public static switchCategory = GlobalComponent.baseURL +'/dictionary/categories/status'; // delete
  public static singleCategory = GlobalComponent.baseURL +'/dictionary/categories/single'; // single
  public static activeCategory = GlobalComponent.baseURL +'/dictionary/categories/active'; // single

  // Certificate Templates
  public static createTemplate = GlobalComponent.baseURL +'/dictionary/templates/create'; // Create
  public static UpdateTemplate = GlobalComponent.baseURL +'/dictionary/templates/update'; // update
  public static listTemplate = GlobalComponent.baseURL +'/dictionary/templates/list'; // listing
  public static switchTemplate = GlobalComponent.baseURL +'/dictionary/templates/status'; // delete
  public static singleTemplate = GlobalComponent.baseURL +'/dictionary/templates/single'; // single
  public static activeTemplate = GlobalComponent.baseURL +'/dictionary/templates/active'; // single


  public static getUserProfile = GlobalComponent.baseURL + 'utility/shared/user';
  public static updateUserProfile = GlobalComponent.baseURL + 'utility/shared/update-user-basic';
  public static updateUserPassword = GlobalComponent.baseURL + 'utility/shared/change-user-password';
  public static getNotifications = GlobalComponent.baseURL +'utility/shared/notifications';
  public static markNotifications = GlobalComponent.baseURL +'utility/shared//mark_notifications';

  public static adminDashboard = GlobalComponent.baseURL +'/user/admin/dashboard';
  public static learnerDashboard = GlobalComponent.baseURL +'/user/learner/dashboard';
  public static adminReport = GlobalComponent.baseURL +'/user/reports';
  public static coursePlayer = GlobalComponent.baseURL +'/user/course-player';
  public static courseContent = GlobalComponent.baseURL +'/user/course-content';
  public static courseEnrollment = GlobalComponent.baseURL +'/user/course-enrollment';
  public static tenantSettings = GlobalComponent.baseURL +'/user/tenant-settings';


  public static myLearning = GlobalComponent.baseURL +'/user/my-learning';
  public static myReport = GlobalComponent.baseURL +'/user/learner-report';
  public static skills = GlobalComponent.baseURL +'/user/skills';
  public static liveTraining = GlobalComponent.baseURL +'/user/live-training';
  public static internalMessaging = GlobalComponent.baseURL +'/user/messaging';
  public static im_uploads = GlobalComponent.baseURL +'/user/uploads';
  public static integration_redirect = GlobalComponent.baseURL +'/user/uploads';
  public static training_enrollment = GlobalComponent.baseURL +'/user/training-enrollment';

  public static learning_path = GlobalComponent.baseURL +'/user/learning-paths';

  public static question_bank = GlobalComponent.baseURL +'/user/question-bank';
  public static assessment = GlobalComponent.baseURL +'/user/assessment';
  public static attempt = GlobalComponent.baseURL +'/user/attempt';
  public static proctoring = GlobalComponent.baseURL +'/user/proctoring';
  public static grading = GlobalComponent.baseURL +'/user/grading';
  public static certificate = GlobalComponent.baseURL +'/user/certificate';
  public static procreports = GlobalComponent.baseURL +'/user/procreports';
  public static users = GlobalComponent.baseURL +'/user/users';
  public static editcourse = GlobalComponent.baseURL +'/user/courses';

  public static reporting = GlobalComponent.baseURL +'/api/reports';

  static validateEmail(email: string) {
    return !!email.match(/(?:[a-z0-9+!#$%&'*\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+\/=?^_`{|}~-]+)*|"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)])/gi);
  }
  static validateNumber(number: string){
    let numbers = /^[0-9]+$/;
    return !!number.match(numbers);
  }
  static decodeJWT(token: string): any {
    const base64Url = token.split('.')[1];
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
    // Decode base64
    const jsonPayload = decodeURIComponent(
      atob(base64)
        .split('')
        .map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
        .join('')
    );
    return JSON.parse(jsonPayload);
  }
  safeGetFirstFileFromEvent(ev: Event): File | null {
    const target = ev?.target as HTMLInputElement | null;
    if (!target) return null;
    const files = target.files;
    if (!files || files.length === 0) return null;
    return files[0];
  }
  static encodeBase64(obj: any): string {
    const json = JSON.stringify(obj);
    const bytes = new TextEncoder().encode(json);
    let binary = '';
    bytes.forEach(b => binary += String.fromCharCode(b));
    return btoa(binary);
  }

  static decodeBase64<T = any>(base64: string): T {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    const json = new TextDecoder().decode(bytes);
    return JSON.parse(json) as T;
  }
}
