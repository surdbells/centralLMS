export class Notifications {
  constructor(
    public id: number,
    public user: number,
    public message: string,
    public is_read: boolean,
    public string: string,
    public created_at: string,
  ){  }
}
