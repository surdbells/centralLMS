import { Component } from '@angular/core';
import {RouterLink} from '@angular/router';
import { TranslatePipe } from '../../translate.pipe';

@Component({
  selector: 'app-home-top',
  standalone: true,
  imports: [
    RouterLink,
    TranslatePipe
  ],
  templateUrl: './home-top.component.html',
  styleUrl: './home-top.component.css'
})
export class HomeTopComponent {

}
