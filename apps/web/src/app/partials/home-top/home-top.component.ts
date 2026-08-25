import { Component } from '@angular/core';
import {RouterLink} from '@angular/router';

@Component({
  selector: 'app-home-top',
  standalone: true,
  imports: [
    RouterLink
  ],
  templateUrl: './home-top.component.html',
  styleUrl: './home-top.component.css'
})
export class HomeTopComponent {

}
